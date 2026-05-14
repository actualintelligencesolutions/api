<?php

declare(strict_types=1);

final class DashboardService extends BaseService
{
    public function __construct(
        PDO $db,
        private readonly LeaveBalanceService $leaveBalanceService,
        private readonly LeaveService $leaveService
    ) {
        parent::__construct($db);
    }

    public function summary(array $actor): array
    {
        $user = $this->findUserById((int) $actor['id'], true);
        if ($user === null) {
            throw new RuntimeException('User not found.', 404);
        }

        $balanceSummary = $this->leaveBalanceService->balancesForUser((int) $actor['id'], (int) gmdate('Y'));
        $recentLeaves = $this->leaveService->listForMe($actor, ['page' => 1, 'per_page' => 5]);
        $pendingApprovalCount = $this->countPendingApprovals($actor);

        $holidayStmt = $this->db->query(
            "SELECT *
             FROM holidays
             WHERE holiday_date >= CURDATE()
             ORDER BY holiday_date ASC
             LIMIT 5"
        );

        return [
            'status' => 200,
            'data' => [
                'profile' => [
                    'id' => (int) $user['id'],
                    'employee_code' => $user['employee_code'],
                    'full_name' => $user['full_name'],
                    'mobile' => $user['mobile'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'status' => $user['status'],
                ],
                'balances' => $balanceSummary['data']['items'],
                'recent_leaves' => $recentLeaves['data']['items'],
                'pending_approvals_count' => $pendingApprovalCount,
                'upcoming_holidays' => array_map(
                    static fn (array $holiday): array => [
                        'id' => (int) $holiday['id'],
                        'holiday_date' => $holiday['holiday_date'],
                        'name' => $holiday['name'],
                        'holiday_type' => $holiday['holiday_type'],
                        'location_code' => $holiday['location_code'],
                        'is_optional' => (bool) $holiday['is_optional'],
                    ],
                    $holidayStmt->fetchAll()
                ),
            ],
        ];
    }

    private function countPendingApprovals(array $actor): int
    {
        if (!in_array($actor['role'], ['admin', 'super_admin'], true)) {
            return 0;
        }

        if ($actor['role'] === 'super_admin' && env('ALLOW_SUPER_ADMIN_APPROVAL_OVERRIDE', 'true') === 'true') {
            $stmt = $this->db->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'");
            return (int) $stmt->fetchColumn();
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT lr.id)
             FROM leave_requests lr
             INNER JOIN approver_group_members agm
                ON agm.approver_group_id = lr.approver_group_id
               AND agm.user_id = :user_id
               AND agm.status = 'active'
             WHERE lr.status = 'pending'"
        );
        $stmt->execute(['user_id' => $actor['id']]);

        return (int) $stmt->fetchColumn();
    }
}
