<?php

declare(strict_types=1);

abstract class BaseService
{
    public function __construct(
        protected readonly PDO $db
    ) {
    }

    protected function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    protected function requireRole(array $user, array $roles): void
    {
        if (!in_array($user['role'], $roles, true)) {
            throw new RuntimeException('You are not authorized to perform this action.', 403);
        }
    }

    protected function requireSuperAdmin(array $user): void
    {
        $this->requireRole($user, ['super_admin']);
    }

    protected function findUserById(int $userId, bool $includeInactive = false): ?array
    {
        $sql = 'SELECT u.*,
                       d.name AS department_name,
                       g.name AS designation_name,
                       ag.name AS approver_group_name
                FROM users u
                LEFT JOIN departments d ON d.id = u.department_id
                LEFT JOIN designations g ON g.id = u.designation_id
                LEFT JOIN approver_groups ag ON ag.id = u.approver_group_id
                WHERE u.id = :id';

        if (!$includeInactive) {
            $sql .= " AND u.status = 'active'";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        return is_array($user) ? $user : null;
    }

    protected function fetchPaginated(string $baseSql, array $params, int $page, int $perPage, string $orderBy): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $countSql = 'SELECT COUNT(*) FROM (' . $baseSql . ') AS paged_list';
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = $baseSql . ' ORDER BY ' . $orderBy . ' LIMIT :limit OFFSET :offset';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    protected function normalizeStatus(?string $status, array $allowed): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('status has an invalid value.', 422);
        }

        return $status;
    }

    protected function requireActiveApproverGroupForUser(array $user): array
    {
        $groupId = isset($user['approver_group_id']) ? (int) $user['approver_group_id'] : 0;
        if ($groupId <= 0) {
            throw new InvalidArgumentException('No approver group is assigned for this user.', 422);
        }

        $stmt = $this->db->prepare(
            "SELECT *
             FROM approver_groups
             WHERE id = :id AND status = 'active'"
        );
        $stmt->execute(['id' => $groupId]);
        $group = $stmt->fetch();

        if (!is_array($group)) {
            throw new InvalidArgumentException('Assigned approver group is not active.', 422);
        }

        return $group;
    }

    protected function assertApproverCanReview(array $actor, array $leaveRequest): void
    {
        if ($actor['role'] === 'super_admin' && env('ALLOW_SUPER_ADMIN_APPROVAL_OVERRIDE', 'true') === 'true') {
            return;
        }

        $stmt = $this->db->prepare(
            "SELECT id
             FROM approver_group_members
             WHERE approver_group_id = :approver_group_id
               AND user_id = :user_id
               AND status = 'active'"
        );
        $stmt->execute([
            'approver_group_id' => $leaveRequest['approver_group_id'],
            'user_id' => $actor['id'],
        ]);

        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('You are not allowed to review this leave request.', 403);
        }
    }

    protected function assertSameUserOrPrivileged(array $actor, int $targetUserId): void
    {
        if ((int) $actor['id'] === $targetUserId) {
            return;
        }

        $this->requireRole($actor, ['admin', 'super_admin']);
    }
}
