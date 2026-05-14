<?php

declare(strict_types=1);

final class CompOffService extends BaseService
{
    public function myCredits(array $actor, array $query): array
    {
        return $this->listCredits($actor, array_merge($query, ['user_id' => $actor['id']]), true);
    }

    public function listCredits(array $actor, array $query, bool $selfOnly = false): array
    {
        if (!$selfOnly) {
            $this->requireRole($actor, ['admin', 'super_admin']);
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['per_page'] ?? 20)));
        $userId = Validator::optionalInt($query['user_id'] ?? null, 'user_id');
        $status = Validator::optionalEnum($query['status'] ?? null, 'status', ['active', 'expired', 'consumed', 'cancelled']);

        $where = [];
        $params = [];
        if ($userId !== null) {
            $where[] = 'c.user_id = :user_id';
            $params['user_id'] = $userId;
        }
        if ($status !== null) {
            $where[] = 'c.status = :status';
            $params['status'] = $status;
        }

        $sql = 'SELECT c.*,
                       u.full_name,
                       u.employee_code,
                       gu.full_name AS granted_by_name
                FROM comp_off_credits c
                INNER JOIN users u ON u.id = c.user_id
                INNER JOIN users gu ON gu.id = c.granted_by_user_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $result = $this->fetchPaginated($sql, $params, $page, $perPage, 'c.expiry_date ASC');

        return [
            'status' => 200,
            'data' => [
                'items' => array_map([$this, 'serializeCredit'], $result['items']),
                'pagination' => $result['pagination'],
            ],
        ];
    }

    public function create(array $actor, array $input): array
    {
        $this->requireRole($actor, ['admin', 'super_admin']);

        $userId = Validator::requiredInt($input['user_id'] ?? null, 'user_id');
        $sourceDate = Validator::requiredDate($input['source_date'] ?? null, 'source_date');
        $units = Validator::requiredNumeric($input['units'] ?? null, 'units');
        $expiryDate = Validator::requiredDate($input['expiry_date'] ?? null, 'expiry_date');
        $status = Validator::optionalEnum($input['status'] ?? null, 'status', ['active', 'expired', 'consumed', 'cancelled']) ?? 'active';
        $notes = Validator::optionalString($input['notes'] ?? null, 'notes', 1000);

        if ($units <= 0) {
            throw new InvalidArgumentException('units must be greater than zero.', 422);
        }

        if ($this->findUserById($userId, true) === null) {
            throw new InvalidArgumentException('User not found.', 422);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO comp_off_credits (
                user_id,
                granted_by_user_id,
                source_date,
                units,
                used_units,
                expiry_date,
                status,
                notes,
                created_at,
                updated_at
            ) VALUES (
                :user_id,
                :granted_by_user_id,
                :source_date,
                :units,
                0,
                :expiry_date,
                :status,
                :notes,
                UTC_TIMESTAMP(),
                UTC_TIMESTAMP()
            )'
        );
        $stmt->execute([
            'user_id' => $userId,
            'granted_by_user_id' => $actor['id'],
            'source_date' => $sourceDate,
            'units' => $units,
            'expiry_date' => $expiryDate,
            'status' => $status,
            'notes' => $notes,
        ]);

        $credit = $this->findCredit((int) $this->db->lastInsertId());
        if ($credit === null) {
            throw new RuntimeException('Comp off credit could not be loaded after creation.', 500);
        }

        return [
            'status' => 201,
            'data' => [
                'comp_off_credit' => $this->serializeCredit($credit),
            ],
        ];
    }

    public function get(array $actor, int $creditId): array
    {
        $this->requireRole($actor, ['admin', 'super_admin']);
        $credit = $this->findCredit($creditId);
        if ($credit === null) {
            throw new RuntimeException('Comp off credit could not be loaded after update.', 500);
        }
        if ($credit === null) {
            throw new RuntimeException('Comp off credit not found.', 404);
        }

        return [
            'status' => 200,
            'data' => [
                'comp_off_credit' => $this->serializeCredit($credit),
            ],
        ];
    }

    public function update(array $actor, int $creditId, array $input, bool $partial): array
    {
        $this->requireRole($actor, ['admin', 'super_admin']);
        $existing = $this->findCredit($creditId);
        if ($existing === null) {
            throw new RuntimeException('Comp off credit not found.', 404);
        }

        $payload = $partial ? array_merge($existing, $input) : $input;
        $sourceDate = Validator::requiredDate($payload['source_date'] ?? null, 'source_date');
        $units = Validator::requiredNumeric($payload['units'] ?? null, 'units');
        $usedUnits = Validator::requiredNumeric($payload['used_units'] ?? null, 'used_units');
        $expiryDate = Validator::requiredDate($payload['expiry_date'] ?? null, 'expiry_date');
        $status = Validator::optionalEnum($payload['status'] ?? null, 'status', ['active', 'expired', 'consumed', 'cancelled']) ?? 'active';
        $notes = Validator::optionalString($payload['notes'] ?? null, 'notes', 1000);

        if ($usedUnits > $units) {
            throw new InvalidArgumentException('used_units cannot exceed units.', 422);
        }

        $stmt = $this->db->prepare(
            'UPDATE comp_off_credits
             SET source_date = :source_date,
                 units = :units,
                 used_units = :used_units,
                 expiry_date = :expiry_date,
                 status = :status,
                 notes = :notes,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $stmt->execute([
            'source_date' => $sourceDate,
            'units' => $units,
            'used_units' => $usedUnits,
            'expiry_date' => $expiryDate,
            'status' => $status,
            'notes' => $notes,
            'id' => $creditId,
        ]);

        $credit = $this->findCredit($creditId);

        return [
            'status' => 200,
            'data' => [
                'comp_off_credit' => $this->serializeCredit($credit),
            ],
        ];
    }

    public function consumeCreditsForLeave(int $userId, int $leaveRequestId, float $requiredUnits): void
    {
        if ($requiredUnits <= 0) {
            return;
        }

        $stmt = $this->db->prepare(
            "SELECT *
             FROM comp_off_credits
             WHERE user_id = :user_id
               AND status = 'active'
               AND expiry_date >= CURDATE()
               AND units > used_units
             ORDER BY expiry_date ASC, created_at ASC
             FOR UPDATE"
        );
        $stmt->execute(['user_id' => $userId]);
        $credits = $stmt->fetchAll();

        $remaining = $requiredUnits;
        foreach ($credits as $credit) {
            if ($remaining <= 0) {
                break;
            }

            $available = round((float) $credit['units'] - (float) $credit['used_units'], 2);
            if ($available <= 0) {
                continue;
            }

            $consume = min($remaining, $available);

            $update = $this->db->prepare(
                "UPDATE comp_off_credits
                 SET used_units = used_units + :consume,
                     status = CASE
                         WHEN used_units + :consume >= units THEN 'consumed'
                         ELSE status
                     END,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id"
            );
            $update->execute([
                'consume' => $consume,
                'id' => $credit['id'],
            ]);

            $usage = $this->db->prepare(
                'INSERT INTO comp_off_usages (
                    comp_off_credit_id,
                    leave_request_id,
                    units_used,
                    created_at
                ) VALUES (
                    :comp_off_credit_id,
                    :leave_request_id,
                    :units_used,
                    UTC_TIMESTAMP()
                )'
            );
            $usage->execute([
                'comp_off_credit_id' => $credit['id'],
                'leave_request_id' => $leaveRequestId,
                'units_used' => $consume,
            ]);

            $remaining = round($remaining - $consume, 2);
        }
    }

    public function reverseLeaveUsage(int $leaveRequestId): void
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM comp_off_usages WHERE leave_request_id = :leave_request_id'
        );
        $stmt->execute(['leave_request_id' => $leaveRequestId]);
        $usages = $stmt->fetchAll();

        foreach ($usages as $usage) {
            $update = $this->db->prepare(
                "UPDATE comp_off_credits
                 SET used_units = GREATEST(0, used_units - :units_used),
                     status = CASE
                         WHEN status = 'consumed' THEN 'active'
                         ELSE status
                     END,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id"
            );
            $update->execute([
                'units_used' => $usage['units_used'],
                'id' => $usage['comp_off_credit_id'],
            ]);
        }

        $delete = $this->db->prepare('DELETE FROM comp_off_usages WHERE leave_request_id = :leave_request_id');
        $delete->execute(['leave_request_id' => $leaveRequestId]);
    }

    private function findCredit(int $creditId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*,
                    u.full_name,
                    u.employee_code,
                    gu.full_name AS granted_by_name
             FROM comp_off_credits c
             INNER JOIN users u ON u.id = c.user_id
             INNER JOIN users gu ON gu.id = c.granted_by_user_id
             WHERE c.id = :id"
        );
        $stmt->execute(['id' => $creditId]);
        $credit = $stmt->fetch();

        return is_array($credit) ? $credit : null;
    }

    private function serializeCredit(array $credit): array
    {
        return [
            'id' => isset($credit['id']) ? (int) $credit['id'] : null,
            'user_id' => isset($credit['user_id']) ? (int) $credit['user_id'] : null,
            'employee_code' => $credit['employee_code'] ?? null,
            'full_name' => $credit['full_name'] ?? null,
            'granted_by_user_id' => isset($credit['granted_by_user_id']) ? (int) $credit['granted_by_user_id'] : null,
            'granted_by_name' => $credit['granted_by_name'] ?? null,
            'source_date' => $credit['source_date'] ?? null,
            'units' => isset($credit['units']) ? (float) $credit['units'] : null,
            'used_units' => isset($credit['used_units']) ? (float) $credit['used_units'] : null,
            'remaining_units' => isset($credit['units'], $credit['used_units']) ? round((float) $credit['units'] - (float) $credit['used_units'], 2) : null,
            'expiry_date' => $credit['expiry_date'] ?? null,
            'status' => $credit['status'] ?? null,
            'notes' => $credit['notes'] ?? null,
        ];
    }
}
