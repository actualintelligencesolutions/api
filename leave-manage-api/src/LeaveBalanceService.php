<?php

declare(strict_types=1);

final class LeaveBalanceService extends BaseService
{
    public function myBalances(array $actor, array $query): array
    {
        $year = Validator::optionalInt($query['period_year'] ?? null, 'period_year') ?? (int) gmdate('Y');
        return $this->balancesForUser((int) $actor['id'], $year);
    }

    public function list(array $actor, array $query): array
    {
        $this->requireRole($actor, ['admin', 'super_admin']);

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['per_page'] ?? 20)));
        $year = Validator::optionalInt($query['period_year'] ?? null, 'period_year');
        $userId = Validator::optionalInt($query['user_id'] ?? null, 'user_id');
        $leaveTypeId = Validator::optionalInt($query['leave_type_id'] ?? null, 'leave_type_id');

        $where = [];
        $params = [];
        if ($year !== null) {
            $where[] = 'ulb.period_year = :period_year';
            $params['period_year'] = $year;
        }
        if ($userId !== null) {
            $where[] = 'ulb.user_id = :user_id';
            $params['user_id'] = $userId;
        }
        if ($leaveTypeId !== null) {
            $where[] = 'ulb.leave_type_id = :leave_type_id';
            $params['leave_type_id'] = $leaveTypeId;
        }

        $sql = 'SELECT ulb.*,
                       u.full_name,
                       u.employee_code,
                       lt.name AS leave_type_name,
                       lt.code AS leave_type_code
                FROM user_leave_balances ulb
                INNER JOIN users u ON u.id = ulb.user_id
                INNER JOIN leave_types lt ON lt.id = ulb.leave_type_id';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $result = $this->fetchPaginated($sql, $params, $page, $perPage, 'ulb.updated_at DESC');

        return [
            'status' => 200,
            'data' => [
                'items' => array_map([$this, 'serializeBalanceRow'], $result['items']),
                'pagination' => $result['pagination'],
            ],
        ];
    }

    public function adjust(array $actor, array $input): array
    {
        $this->requireRole($actor, ['admin', 'super_admin']);

        $userId = Validator::requiredInt($input['user_id'] ?? null, 'user_id');
        $leaveTypeId = Validator::requiredInt($input['leave_type_id'] ?? null, 'leave_type_id');
        $periodYear = Validator::requiredInt($input['period_year'] ?? null, 'period_year');
        $adjustmentType = Validator::enum(
            $input['adjustment_type'] ?? null,
            'adjustment_type',
            ['opening', 'credited', 'used', 'pending']
        );
        $amount = Validator::requiredNumeric($input['amount'] ?? null, 'amount');
        $remarks = Validator::optionalString($input['remarks'] ?? null, 'remarks', 1000);

        $this->ensureUserExists($userId);
        $this->ensureLeaveTypeExists($leaveTypeId);

        $this->db->beginTransaction();
        try {
            $balance = $this->getOrCreateBalanceRecord($userId, $leaveTypeId, $periodYear);
            $field = match ($adjustmentType) {
                'opening' => 'opening_balance',
                'credited' => 'credited_balance',
                'used' => 'used_balance',
                'pending' => 'pending_balance',
            };

            $newValue = round((float) $balance[$field] + $amount, 2);
            if ($newValue < 0) {
                throw new InvalidArgumentException('Adjustment would make the balance negative.', 422);
            }

            $update = $this->db->prepare(
                "UPDATE user_leave_balances
                 SET {$field} = :new_value,
                     available_balance = opening_balance + credited_balance - used_balance - pending_balance,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id"
            );
            $update->execute([
                'new_value' => $newValue,
                'id' => $balance['id'],
            ]);

            if ($adjustmentType === 'pending') {
                $this->touchBalance($balance['id']);
            } else {
                $this->touchBalance($balance['id']);
            }

            $updated = $this->getBalanceById((int) $balance['id']);
            if ($updated === null) {
                throw new RuntimeException('Leave balance not found after adjustment.', 500);
            }

            if ($remarks !== null) {
                $log = $this->db->prepare(
                    'INSERT INTO approval_logs (
                        leave_request_id,
                        action_by_user_id,
                        approver_group_id,
                        action,
                        remarks,
                        action_at
                    ) VALUES (
                        NULL,
                        :action_by_user_id,
                        NULL,
                        :action,
                        :remarks,
                        UTC_TIMESTAMP()
                    )'
                );
                $log->execute([
                    'action_by_user_id' => $actor['id'],
                    'action' => 'balance_adjusted',
                    'remarks' => sprintf(
                        'Balance adjustment [%s]: %.2f for user_id=%d leave_type_id=%d year=%d. %s',
                        $adjustmentType,
                        $amount,
                        $userId,
                        $leaveTypeId,
                        $periodYear,
                        $remarks
                    ),
                ]);
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }

        return [
            'status' => 200,
            'data' => [
                'balance' => $this->serializeBalanceRow($updated),
            ],
        ];
    }

    public function balancesForUser(int $userId, int $periodYear): array
    {
        $stmt = $this->db->prepare(
            'SELECT ulb.*,
                    lt.name AS leave_type_name,
                    lt.code AS leave_type_code
             FROM user_leave_balances ulb
             INNER JOIN leave_types lt ON lt.id = ulb.leave_type_id
             WHERE ulb.user_id = :user_id
               AND ulb.period_year = :period_year
             ORDER BY lt.name ASC'
        );
        $stmt->execute([
            'user_id' => $userId,
            'period_year' => $periodYear,
        ]);

        return [
            'status' => 200,
            'data' => [
                'items' => array_map([$this, 'serializeBalanceRow'], $stmt->fetchAll()),
                'period_year' => $periodYear,
            ],
        ];
    }

    public function getOrCreateBalanceRecord(int $userId, int $leaveTypeId, int $periodYear): array
    {
        $stmt = $this->db->prepare(
            'SELECT *
             FROM user_leave_balances
             WHERE user_id = :user_id
               AND leave_type_id = :leave_type_id
               AND period_year = :period_year
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'leave_type_id' => $leaveTypeId,
            'period_year' => $periodYear,
        ]);
        $balance = $stmt->fetch();
        if (is_array($balance)) {
            return $balance;
        }

        $insert = $this->db->prepare(
            'INSERT INTO user_leave_balances (
                user_id,
                leave_type_id,
                period_year,
                opening_balance,
                credited_balance,
                used_balance,
                pending_balance,
                available_balance,
                updated_at
            ) VALUES (
                :user_id,
                :leave_type_id,
                :period_year,
                0,
                0,
                0,
                0,
                0,
                UTC_TIMESTAMP()
            )'
        );
        $insert->execute([
            'user_id' => $userId,
            'leave_type_id' => $leaveTypeId,
            'period_year' => $periodYear,
        ]);

        $created = $this->getBalanceById((int) $this->db->lastInsertId());
        if ($created === null) {
            throw new RuntimeException('Failed to create leave balance record.', 500);
        }

        return $created;
    }

    public function touchBalance(int $balanceId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE user_leave_balances
             SET available_balance = opening_balance + credited_balance - used_balance - pending_balance,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $stmt->execute(['id' => $balanceId]);
    }

    private function getBalanceById(int $balanceId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ulb.*,
                    u.full_name,
                    u.employee_code,
                    lt.name AS leave_type_name,
                    lt.code AS leave_type_code
             FROM user_leave_balances ulb
             INNER JOIN users u ON u.id = ulb.user_id
             INNER JOIN leave_types lt ON lt.id = ulb.leave_type_id
             WHERE ulb.id = :id'
        );
        $stmt->execute(['id' => $balanceId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function ensureUserExists(int $userId): void
    {
        if ($this->findUserById($userId, true) === null) {
            throw new InvalidArgumentException('User not found.', 422);
        }
    }

    private function ensureLeaveTypeExists(int $leaveTypeId): void
    {
        $stmt = $this->db->prepare('SELECT id FROM leave_types WHERE id = :id');
        $stmt->execute(['id' => $leaveTypeId]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('Leave type not found.', 422);
        }
    }

    private function serializeBalanceRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'employee_code' => $row['employee_code'] ?? null,
            'full_name' => $row['full_name'] ?? null,
            'leave_type_id' => (int) $row['leave_type_id'],
            'leave_type_name' => $row['leave_type_name'] ?? null,
            'leave_type_code' => $row['leave_type_code'] ?? null,
            'period_year' => (int) $row['period_year'],
            'opening_balance' => (float) $row['opening_balance'],
            'credited_balance' => (float) $row['credited_balance'],
            'used_balance' => (float) $row['used_balance'],
            'pending_balance' => (float) $row['pending_balance'],
            'available_balance' => (float) $row['available_balance'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
