<?php

declare(strict_types=1);

final class LeaveService extends BaseService
{
    public function __construct(
        PDO $db,
        private readonly LeaveBalanceService $leaveBalanceService,
        private readonly CompOffService $compOffService
    ) {
        parent::__construct($db);
    }

    public function listForMe(array $actor, array $query): array
    {
        return $this->listRequests($actor, array_merge($query, ['user_id' => $actor['id']]), true);
    }

    public function listRequests(array $actor, array $query, bool $selfOnly = false): array
    {
        if (!$selfOnly) {
            $this->requireRole($actor, ['admin', 'super_admin']);
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['per_page'] ?? 20)));
        $status = Validator::optionalEnum($query['status'] ?? null, 'status', ['pending', 'approved', 'rejected', 'cancelled']);
        $userId = Validator::optionalInt($query['user_id'] ?? null, 'user_id');

        $where = [];
        $params = [];
        if ($status !== null) {
            $where[] = 'lr.status = :status';
            $params['status'] = $status;
        }
        if ($userId !== null) {
            $where[] = 'lr.user_id = :user_id';
            $params['user_id'] = $userId;
        }

        $sql = $this->baseLeaveRequestSql();
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $result = $this->fetchPaginated($sql, $params, $page, $perPage, 'lr.applied_at DESC');

        return [
            'status' => 200,
            'data' => [
                'items' => array_map([$this, 'serializeLeaveRequestSummary'], $result['items']),
                'pagination' => $result['pagination'],
            ],
        ];
    }

    public function getForMe(array $actor, int $leaveRequestId): array
    {
        $request = $this->findLeaveRequest($leaveRequestId);
        if ($request === null) {
            throw new RuntimeException('Leave request could not be loaded after creation.', 500);
        }
        if ($request === null || (int) $request['user_id'] !== (int) $actor['id']) {
            throw new RuntimeException('Leave request not found.', 404);
        }

        return [
            'status' => 200,
            'data' => [
                'leave_request' => $this->serializeLeaveRequestDetail($request),
            ],
        ];
    }

    public function apply(array $actor, array $input): array
    {
        $user = $this->findUserById((int) $actor['id'], true);
        if ($user === null || $user['status'] !== 'active') {
            throw new RuntimeException('User is not active.', 403);
        }

        $leaveTypeId = Validator::requiredInt($input['leave_type_id'] ?? null, 'leave_type_id');
        $startDate = Validator::requiredDate($input['start_date'] ?? null, 'start_date');
        $endDate = Validator::requiredDate($input['end_date'] ?? null, 'end_date');
        $durationMode = Validator::enum($input['duration_mode'] ?? null, 'duration_mode', ['full_day', 'half_day']);
        $reason = Validator::requiredString($input['reason'] ?? null, 'reason', 5000);
        $contact = Validator::optionalString($input['contact_during_leave'] ?? null, 'contact_during_leave', 150);
        $dayPart = Validator::optionalEnum($input['day_part'] ?? null, 'day_part', ['first_half', 'second_half']);

        if ($startDate > $endDate) {
            throw new InvalidArgumentException('start_date must be before or equal to end_date.', 422);
        }

        if (substr($startDate, 0, 4) !== substr($endDate, 0, 4)) {
            throw new InvalidArgumentException('Leave requests cannot span multiple years in v1.', 422);
        }

        if ($durationMode === 'half_day' && $startDate !== $endDate) {
            throw new InvalidArgumentException('half_day leave must start and end on the same date.', 422);
        }

        if ($durationMode === 'half_day' && $dayPart === null) {
            throw new InvalidArgumentException('day_part is required for half_day leave.', 422);
        }

        $leaveType = $this->findLeaveType($leaveTypeId);
        if ($leaveType === null || $leaveType['status'] !== 'active') {
            throw new InvalidArgumentException('Leave type is invalid or inactive.', 422);
        }

        $approverGroup = $this->requireActiveApproverGroupForUser($user);
        $days = $this->buildLeaveDayRows($startDate, $endDate, $durationMode, $dayPart);
        $totalUnits = array_sum(array_column($days, 'units'));

        $this->assertNoHolidayOverlap($days);
        $this->assertNoOverlap((int) $user['id'], $days);

        $periodYear = (int) substr($startDate, 0, 4);

        $this->db->beginTransaction();
        try {
            $balance = $this->leaveBalanceService->getOrCreateBalanceRecord((int) $user['id'], $leaveTypeId, $periodYear);
            $this->leaveBalanceService->touchBalance((int) $balance['id']);
            $balance = $this->leaveBalanceService->getOrCreateBalanceRecord((int) $user['id'], $leaveTypeId, $periodYear);

            if ((float) $balance['available_balance'] < $totalUnits) {
                throw new InvalidArgumentException('Insufficient leave balance.', 422);
            }

            $requestInsert = $this->db->prepare(
                'INSERT INTO leave_requests (
                    user_id,
                    leave_type_id,
                    approver_group_id,
                    approved_by_user_id,
                    start_date,
                    end_date,
                    duration_mode,
                    total_units,
                    reason,
                    contact_during_leave,
                    status,
                    rejection_reason,
                    applied_at,
                    reviewed_at,
                    created_at,
                    updated_at
                ) VALUES (
                    :user_id,
                    :leave_type_id,
                    :approver_group_id,
                    NULL,
                    :start_date,
                    :end_date,
                    :duration_mode,
                    :total_units,
                    :reason,
                    :contact_during_leave,
                    :status,
                    NULL,
                    UTC_TIMESTAMP(),
                    NULL,
                    UTC_TIMESTAMP(),
                    UTC_TIMESTAMP()
                )'
            );
            $requestInsert->execute([
                'user_id' => $user['id'],
                'leave_type_id' => $leaveTypeId,
                'approver_group_id' => $approverGroup['id'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'duration_mode' => $durationMode,
                'total_units' => $totalUnits,
                'reason' => $reason,
                'contact_during_leave' => $contact,
                'status' => 'pending',
            ]);

            $leaveRequestId = (int) $this->db->lastInsertId();

            $dayInsert = $this->db->prepare(
                'INSERT INTO leave_request_days (
                    leave_request_id,
                    leave_date,
                    day_part,
                    units
                ) VALUES (
                    :leave_request_id,
                    :leave_date,
                    :day_part,
                    :units
                )'
            );
            foreach ($days as $day) {
                $dayInsert->execute([
                    'leave_request_id' => $leaveRequestId,
                    'leave_date' => $day['leave_date'],
                    'day_part' => $day['day_part'],
                    'units' => $day['units'],
                ]);
            }

            $updateBalance = $this->db->prepare(
                'UPDATE user_leave_balances
                 SET pending_balance = pending_balance + :units,
                     available_balance = available_balance - :units,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $updateBalance->execute([
                'units' => $totalUnits,
                'id' => $balance['id'],
            ]);

            $this->createApprovalLog($leaveRequestId, (int) $user['id'], (int) $approverGroup['id'], 'applied', $reason);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }

        $request = $this->findLeaveRequest($leaveRequestId);

        return [
            'status' => 201,
            'data' => [
                'leave_request' => $this->serializeLeaveRequestDetail($request),
            ],
        ];
    }

    public function cancel(array $actor, int $leaveRequestId): array
    {
        $request = $this->findLeaveRequest($leaveRequestId, true);
        if ($request === null || (int) $request['user_id'] !== (int) $actor['id']) {
            throw new RuntimeException('Leave request not found.', 404);
        }

        if (!in_array($request['status'], ['pending', 'approved'], true)) {
            throw new InvalidArgumentException('Only pending or approved leave can be cancelled.', 422);
        }

        if ($request['status'] === 'approved' && $request['start_date'] < gmdate('Y-m-d')) {
            throw new InvalidArgumentException('Past approved leave cannot be cancelled.', 422);
        }

        $periodYear = (int) substr((string) $request['start_date'], 0, 4);

        $this->db->beginTransaction();
        try {
            $balance = $this->leaveBalanceService->getOrCreateBalanceRecord((int) $request['user_id'], (int) $request['leave_type_id'], $periodYear);

            if ($request['status'] === 'pending') {
                $stmt = $this->db->prepare(
                    'UPDATE user_leave_balances
                     SET pending_balance = GREATEST(0, pending_balance - :units),
                         available_balance = available_balance + :units,
                         updated_at = UTC_TIMESTAMP()
                     WHERE id = :id'
                );
                $stmt->execute([
                    'units' => $request['total_units'],
                    'id' => $balance['id'],
                ]);
            } else {
                $stmt = $this->db->prepare(
                    'UPDATE user_leave_balances
                     SET used_balance = GREATEST(0, used_balance - :units),
                         available_balance = available_balance + :units,
                         updated_at = UTC_TIMESTAMP()
                     WHERE id = :id'
                );
                $stmt->execute([
                    'units' => $request['total_units'],
                    'id' => $balance['id'],
                ]);

                $this->compOffService->reverseLeaveUsage($leaveRequestId);
            }

            $updateRequest = $this->db->prepare(
                "UPDATE leave_requests
                 SET status = 'cancelled',
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id"
            );
            $updateRequest->execute(['id' => $leaveRequestId]);

            $this->createApprovalLog($leaveRequestId, (int) $actor['id'], (int) $request['approver_group_id'], 'cancelled', 'Cancelled by staff user.');
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }

        $updated = $this->findLeaveRequest($leaveRequestId, true);
        if ($updated === null) {
            throw new RuntimeException('Leave request could not be loaded after cancellation.', 500);
        }

        return [
            'status' => 200,
            'data' => [
                'leave_request' => $this->serializeLeaveRequestDetail($updated),
            ],
        ];
    }

    public function findLeaveRequest(int $leaveRequestId, bool $includeLogs = false): ?array
    {
        $stmt = $this->db->prepare($this->baseLeaveRequestSql() . ' WHERE lr.id = :id LIMIT 1');
        $stmt->execute(['id' => $leaveRequestId]);
        $request = $stmt->fetch();

        if (!is_array($request)) {
            return null;
        }

        if ($includeLogs) {
            $request['days'] = $this->fetchLeaveDays($leaveRequestId);
            $request['approval_logs'] = $this->fetchApprovalLogs($leaveRequestId);
        }

        return $request;
    }

    public function createApprovalLog(int $leaveRequestId, int $actionByUserId, int $approverGroupId, string $action, ?string $remarks): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO approval_logs (
                leave_request_id,
                action_by_user_id,
                approver_group_id,
                action,
                remarks,
                action_at
            ) VALUES (
                :leave_request_id,
                :action_by_user_id,
                :approver_group_id,
                :action,
                :remarks,
                UTC_TIMESTAMP()
            )'
        );
        $stmt->execute([
            'leave_request_id' => $leaveRequestId,
            'action_by_user_id' => $actionByUserId,
            'approver_group_id' => $approverGroupId,
            'action' => $action,
            'remarks' => $remarks,
        ]);
    }

    public function serializeLeaveRequestSummary(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'employee' => [
                'id' => (int) $row['user_id'],
                'full_name' => $row['full_name'],
                'employee_code' => $row['employee_code'],
            ],
            'leave_type' => [
                'id' => (int) $row['leave_type_id'],
                'name' => $row['leave_type_name'],
                'code' => $row['leave_type_code'],
            ],
            'approver_group' => [
                'id' => (int) $row['approver_group_id'],
                'name' => $row['approver_group_name'],
            ],
            'approved_by_user_id' => $row['approved_by_user_id'] === null ? null : (int) $row['approved_by_user_id'],
            'approved_by_name' => $row['approved_by_name'],
            'start_date' => $row['start_date'],
            'end_date' => $row['end_date'],
            'duration_mode' => $row['duration_mode'],
            'total_units' => (float) $row['total_units'],
            'reason' => $row['reason'],
            'contact_during_leave' => $row['contact_during_leave'],
            'status' => $row['status'],
            'rejection_reason' => $row['rejection_reason'],
            'applied_at' => $row['applied_at'],
            'reviewed_at' => $row['reviewed_at'],
        ];
    }

    public function serializeLeaveRequestDetail(array $row): array
    {
        $base = $this->serializeLeaveRequestSummary($row);
        $base['days'] = $row['days'] ?? $this->fetchLeaveDays((int) $row['id']);
        $base['approval_logs'] = $row['approval_logs'] ?? $this->fetchApprovalLogs((int) $row['id']);
        return $base;
    }

    private function baseLeaveRequestSql(): string
    {
        return 'SELECT lr.*,
                       u.full_name,
                       u.employee_code,
                       lt.name AS leave_type_name,
                       lt.code AS leave_type_code,
                       ag.name AS approver_group_name,
                       approver.full_name AS approved_by_name
                FROM leave_requests lr
                INNER JOIN users u ON u.id = lr.user_id
                INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
                INNER JOIN approver_groups ag ON ag.id = lr.approver_group_id
                LEFT JOIN users approver ON approver.id = lr.approved_by_user_id';
    }

    private function findLeaveType(int $leaveTypeId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM leave_types WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $leaveTypeId]);
        $type = $stmt->fetch();

        return is_array($type) ? $type : null;
    }

    private function buildLeaveDayRows(string $startDate, string $endDate, string $durationMode, ?string $dayPart): array
    {
        if ($durationMode === 'half_day') {
            return [[
                'leave_date' => $startDate,
                'day_part' => $dayPart ?? 'first_half',
                'units' => 0.5,
            ]];
        }

        $days = [];
        $current = new DateTimeImmutable($startDate);
        $end = new DateTimeImmutable($endDate);

        while ($current <= $end) {
            $days[] = [
                'leave_date' => $current->format('Y-m-d'),
                'day_part' => 'full',
                'units' => 1.0,
            ];
            $current = $current->modify('+1 day');
        }

        return $days;
    }

    private function assertNoHolidayOverlap(array $days): void
    {
        $dates = array_map(fn (array $day): string => $day['leave_date'], $days);
        $placeholders = implode(', ', array_fill(0, count($dates), '?'));
        $stmt = $this->db->prepare(
            "SELECT holiday_date, name
             FROM holidays
             WHERE holiday_date IN ({$placeholders})
               AND is_optional = 0"
        );
        $stmt->execute($dates);
        $holiday = $stmt->fetch();

        if (is_array($holiday)) {
            throw new InvalidArgumentException(
                'Leave request overlaps with holiday ' . $holiday['name'] . ' on ' . $holiday['holiday_date'] . '.',
                422
            );
        }
    }

    private function assertNoOverlap(int $userId, array $days): void
    {
        $dates = array_map(fn (array $day): string => $day['leave_date'], $days);
        $placeholders = implode(', ', array_fill(0, count($dates), '?'));
        $params = array_merge([$userId], $dates);
        $stmt = $this->db->prepare(
            "SELECT DISTINCT lr.id
             FROM leave_requests lr
             INNER JOIN leave_request_days lrd ON lrd.leave_request_id = lr.id
             WHERE lr.user_id = ?
               AND lr.status IN ('pending', 'approved')
               AND lrd.leave_date IN ({$placeholders})
             LIMIT 1"
        );
        $stmt->execute($params);

        if ($stmt->fetchColumn()) {
            throw new InvalidArgumentException('Leave request overlaps with an existing pending or approved leave.', 422);
        }
    }

    private function fetchLeaveDays(int $leaveRequestId): array
    {
        $stmt = $this->db->prepare(
            'SELECT leave_date, day_part, units
             FROM leave_request_days
             WHERE leave_request_id = :leave_request_id
             ORDER BY leave_date ASC'
        );
        $stmt->execute(['leave_request_id' => $leaveRequestId]);
        return array_map(
            static fn (array $day): array => [
                'leave_date' => $day['leave_date'],
                'day_part' => $day['day_part'],
                'units' => (float) $day['units'],
            ],
            $stmt->fetchAll()
        );
    }

    private function fetchApprovalLogs(int $leaveRequestId): array
    {
        $stmt = $this->db->prepare(
            'SELECT al.*,
                    u.full_name,
                    ag.name AS approver_group_name
             FROM approval_logs al
             INNER JOIN users u ON u.id = al.action_by_user_id
             INNER JOIN approver_groups ag ON ag.id = al.approver_group_id
             WHERE al.leave_request_id = :leave_request_id
             ORDER BY al.action_at ASC'
        );
        $stmt->execute(['leave_request_id' => $leaveRequestId]);

        return array_map(
            static fn (array $log): array => [
                'id' => (int) $log['id'],
                'action' => $log['action'],
                'remarks' => $log['remarks'],
                'action_at' => $log['action_at'],
                'action_by' => [
                    'id' => (int) $log['action_by_user_id'],
                    'full_name' => $log['full_name'],
                ],
                'approver_group' => [
                    'id' => (int) $log['approver_group_id'],
                    'name' => $log['approver_group_name'],
                ],
            ],
            $stmt->fetchAll()
        );
    }
}
