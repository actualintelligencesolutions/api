<?php

declare(strict_types=1);

final class ApprovalService extends BaseService
{
    public function __construct(
        PDO $db,
        private readonly LeaveService $leaveService,
        private readonly LeaveBalanceService $leaveBalanceService,
        private readonly CompOffService $compOffService
    ) {
        parent::__construct($db);
    }

    public function pending(array $actor, array $query): array
    {
        $this->requireRole($actor, ['admin', 'super_admin']);

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['per_page'] ?? 20)));

        $params = [];
        $sql = 'SELECT DISTINCT lr.id,
                       lr.user_id,
                       lr.leave_type_id,
                       lr.approver_group_id,
                       lr.approved_by_user_id,
                       lr.start_date,
                       lr.end_date,
                       lr.duration_mode,
                       lr.total_units,
                       lr.reason,
                       lr.contact_during_leave,
                       lr.status,
                       lr.rejection_reason,
                       lr.applied_at,
                       lr.reviewed_at,
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

        if ($actor['role'] === 'super_admin' && env('ALLOW_SUPER_ADMIN_APPROVAL_OVERRIDE', 'true') === 'true') {
            $sql .= " WHERE lr.status = 'pending'";
        } else {
            $sql .= " INNER JOIN approver_group_members agm
                        ON agm.approver_group_id = lr.approver_group_id
                       AND agm.user_id = :user_id
                       AND agm.status = 'active'
                      WHERE lr.status = 'pending'";
            $params['user_id'] = $actor['id'];
        }

        $result = $this->fetchPaginated($sql, $params, $page, $perPage, 'lr.applied_at ASC');

        return [
            'status' => 200,
            'data' => [
                'items' => array_map([$this->leaveService, 'serializeLeaveRequestSummary'], $result['items']),
                'pagination' => $result['pagination'],
            ],
        ];
    }

    public function detail(array $actor, int $leaveRequestId): array
    {
        $this->requireRole($actor, ['admin', 'super_admin']);
        $request = $this->leaveService->findLeaveRequest($leaveRequestId, true);
        if ($request === null) {
            throw new RuntimeException('Leave request not found.', 404);
        }

        $this->assertApproverCanReview($actor, $request);

        return [
            'status' => 200,
            'data' => [
                'leave_request' => $this->leaveService->serializeLeaveRequestDetail($request),
            ],
        ];
    }

    public function approve(array $actor, int $leaveRequestId, array $input): array
    {
        $this->requireRole($actor, ['admin', 'super_admin']);
        $remarks = Validator::optionalString($input['remarks'] ?? null, 'remarks', 1000);

        $this->db->beginTransaction();
        try {
            $request = $this->leaveService->findLeaveRequest($leaveRequestId, true);
            if ($request === null) {
                throw new RuntimeException('Leave request not found.', 404);
            }

            if ($request['status'] !== 'pending') {
                throw new InvalidArgumentException('Only pending leave can be approved.', 422);
            }

            $this->assertApproverCanReview($actor, $request);
            $periodYear = (int) substr((string) $request['start_date'], 0, 4);

            $balance = $this->leaveBalanceService->getOrCreateBalanceRecord((int) $request['user_id'], (int) $request['leave_type_id'], $periodYear);

            $balanceUpdate = $this->db->prepare(
                'UPDATE user_leave_balances
                 SET pending_balance = GREATEST(0, pending_balance - :units),
                     used_balance = used_balance + :units,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $balanceUpdate->execute([
                'units' => $request['total_units'],
                'id' => $balance['id'],
            ]);
            $this->leaveBalanceService->touchBalance((int) $balance['id']);

            $requestUpdate = $this->db->prepare(
                "UPDATE leave_requests
                 SET status = 'approved',
                     approved_by_user_id = :approved_by_user_id,
                     reviewed_at = UTC_TIMESTAMP(),
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id"
            );
            $requestUpdate->execute([
                'approved_by_user_id' => $actor['id'],
                'id' => $leaveRequestId,
            ]);

            $leaveType = $this->findLeaveType((int) $request['leave_type_id']);
            if ($leaveType !== null && (int) $leaveType['allow_comp_off'] === 1) {
                $this->compOffService->consumeCreditsForLeave((int) $request['user_id'], $leaveRequestId, (float) $request['total_units']);
            }

            $this->leaveService->createApprovalLog($leaveRequestId, (int) $actor['id'], (int) $request['approver_group_id'], 'approved', $remarks);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }

        $updated = $this->leaveService->findLeaveRequest($leaveRequestId, true);
        if ($updated === null) {
            throw new RuntimeException('Leave request could not be loaded after approval.', 500);
        }

        return [
            'status' => 200,
            'data' => [
                'leave_request' => $this->leaveService->serializeLeaveRequestDetail($updated),
            ],
        ];
    }

    public function reject(array $actor, int $leaveRequestId, array $input): array
    {
        $this->requireRole($actor, ['admin', 'super_admin']);
        $remarks = Validator::requiredString($input['remarks'] ?? null, 'remarks', 1000);

        $this->db->beginTransaction();
        try {
            $request = $this->leaveService->findLeaveRequest($leaveRequestId, true);
            if ($request === null) {
                throw new RuntimeException('Leave request not found.', 404);
            }

            if ($request['status'] !== 'pending') {
                throw new InvalidArgumentException('Only pending leave can be rejected.', 422);
            }

            $this->assertApproverCanReview($actor, $request);
            $periodYear = (int) substr((string) $request['start_date'], 0, 4);
            $balance = $this->leaveBalanceService->getOrCreateBalanceRecord((int) $request['user_id'], (int) $request['leave_type_id'], $periodYear);

            $balanceUpdate = $this->db->prepare(
                'UPDATE user_leave_balances
                 SET pending_balance = GREATEST(0, pending_balance - :units),
                     available_balance = available_balance + :units,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $balanceUpdate->execute([
                'units' => $request['total_units'],
                'id' => $balance['id'],
            ]);

            $requestUpdate = $this->db->prepare(
                "UPDATE leave_requests
                 SET status = 'rejected',
                     rejection_reason = :rejection_reason,
                     approved_by_user_id = :approved_by_user_id,
                     reviewed_at = UTC_TIMESTAMP(),
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id"
            );
            $requestUpdate->execute([
                'rejection_reason' => $remarks,
                'approved_by_user_id' => $actor['id'],
                'id' => $leaveRequestId,
            ]);

            $this->leaveService->createApprovalLog($leaveRequestId, (int) $actor['id'], (int) $request['approver_group_id'], 'rejected', $remarks);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }

        $updated = $this->leaveService->findLeaveRequest($leaveRequestId, true);
        if ($updated === null) {
            throw new RuntimeException('Leave request could not be loaded after rejection.', 500);
        }

        return [
            'status' => 200,
            'data' => [
                'leave_request' => $this->leaveService->serializeLeaveRequestDetail($updated),
            ],
        ];
    }

    private function findLeaveType(int $leaveTypeId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM leave_types WHERE id = :id');
        $stmt->execute(['id' => $leaveTypeId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
