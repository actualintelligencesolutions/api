<?php

declare(strict_types=1);

final class ApproverGroupService extends BaseService
{
    public function list(array $actor, array $query): array
    {
        $this->requireSuperAdmin($actor);

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['per_page'] ?? 20)));
        $status = $this->normalizeStatus($query['status'] ?? null, ['active', 'inactive']);
        $search = Validator::optionalString($query['search'] ?? null, 'search', 100);

        $where = [];
        $params = [];
        if ($status !== null) {
            $where[] = 'ag.status = :status';
            $params['status'] = $status;
        }
        if ($search !== null) {
            $where[] = '(ag.name LIKE :search OR ag.code LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $sql = 'SELECT ag.*,
                       COUNT(agm.id) AS active_member_count
                FROM approver_groups ag
                LEFT JOIN approver_group_members agm
                    ON agm.approver_group_id = ag.id
                   AND agm.status = "active"';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' GROUP BY ag.id';

        $result = $this->fetchPaginated($sql, $params, $page, $perPage, 'ag.created_at DESC');

        return [
            'status' => 200,
            'data' => [
                'items' => array_map([$this, 'serializeGroup'], $result['items']),
                'pagination' => $result['pagination'],
            ],
        ];
    }

    public function create(array $actor, array $input): array
    {
        $this->requireSuperAdmin($actor);

        $stmt = $this->db->prepare(
            'INSERT INTO approver_groups (
                name,
                code,
                description,
                status,
                created_at,
                updated_at
            ) VALUES (
                :name,
                :code,
                :description,
                :status,
                UTC_TIMESTAMP(),
                UTC_TIMESTAMP()
            )'
        );
        $stmt->execute($this->validateGroupPayload($input));

        $group = $this->findGroupById((int) $this->db->lastInsertId());
        if ($group === null) {
            throw new RuntimeException('Approver group could not be loaded after creation.', 500);
        }

        return [
            'status' => 201,
            'data' => [
                'group' => $this->serializeGroup($group),
            ],
        ];
    }

    public function get(array $actor, int $groupId): array
    {
        $this->requireSuperAdmin($actor);
        $group = $this->findGroupById($groupId);
        if ($group === null) {
            throw new RuntimeException('Approver group could not be loaded after update.', 500);
        }
        if ($group === null) {
            throw new RuntimeException('Approver group not found.', 404);
        }

        return [
            'status' => 200,
            'data' => [
                'group' => $this->serializeGroup($group),
            ],
        ];
    }

    public function update(array $actor, int $groupId, array $input, bool $partial): array
    {
        $this->requireSuperAdmin($actor);
        $existing = $this->findGroupById($groupId);
        if ($existing === null) {
            throw new RuntimeException('Approver group not found.', 404);
        }

        $validated = $this->validateGroupPayload($partial ? array_merge($existing, $input) : $input);
        $validated['id'] = $groupId;

        $stmt = $this->db->prepare(
            'UPDATE approver_groups
             SET name = :name,
                 code = :code,
                 description = :description,
                 status = :status,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $stmt->execute($validated);

        $group = $this->findGroupById($groupId);

        return [
            'status' => 200,
            'data' => [
                'group' => $this->serializeGroup($group),
            ],
        ];
    }

    public function listMembers(array $actor, int $groupId): array
    {
        $this->requireSuperAdmin($actor);
        $this->assertGroupExists($groupId);

        $stmt = $this->db->prepare(
            "SELECT agm.*, u.full_name, u.mobile, u.role
             FROM approver_group_members agm
             INNER JOIN users u ON u.id = agm.user_id
             WHERE agm.approver_group_id = :group_id
             ORDER BY agm.created_at DESC"
        );
        $stmt->execute(['group_id' => $groupId]);

        return [
            'status' => 200,
            'data' => [
                'items' => array_map([$this, 'serializeMember'], $stmt->fetchAll()),
            ],
        ];
    }

    public function createMember(array $actor, int $groupId, array $input): array
    {
        $this->requireSuperAdmin($actor);
        $this->assertGroupExists($groupId);

        $userId = Validator::requiredInt($input['user_id'] ?? null, 'user_id');
        $memberRole = Validator::optionalEnum($input['member_role'] ?? null, 'member_role', ['member', 'primary']) ?? 'member';
        $status = Validator::optionalEnum($input['status'] ?? null, 'status', ['active', 'inactive']) ?? 'active';

        $user = $this->findUserById($userId, true);
        if ($user === null || !in_array($user['role'], ['admin', 'super_admin'], true)) {
            throw new InvalidArgumentException('Only admin or super_admin users can be approver group members.', 422);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO approver_group_members (
                approver_group_id,
                user_id,
                member_role,
                status,
                created_at,
                updated_at
            ) VALUES (
                :approver_group_id,
                :user_id,
                :member_role,
                :status,
                UTC_TIMESTAMP(),
                UTC_TIMESTAMP()
            )'
        );
        $stmt->execute([
            'approver_group_id' => $groupId,
            'user_id' => $userId,
            'member_role' => $memberRole,
            'status' => $status,
        ]);

        $member = $this->findMember($groupId, (int) $this->db->lastInsertId());
        if ($member === null) {
            throw new RuntimeException('Approver group member could not be loaded after creation.', 500);
        }

        return [
            'status' => 201,
            'data' => [
                'member' => $this->serializeMember($member),
            ],
        ];
    }

    public function updateMember(array $actor, int $groupId, int $memberId, array $input): array
    {
        $this->requireSuperAdmin($actor);
        $existing = $this->findMember($groupId, $memberId);
        if ($existing === null) {
            throw new RuntimeException('Approver group member not found.', 404);
        }

        $payload = array_merge($existing, $input);
        $memberRole = Validator::optionalEnum($payload['member_role'] ?? null, 'member_role', ['member', 'primary']) ?? 'member';
        $status = Validator::optionalEnum($payload['status'] ?? null, 'status', ['active', 'inactive']) ?? 'active';

        $stmt = $this->db->prepare(
            'UPDATE approver_group_members
             SET member_role = :member_role,
                 status = :status,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id
               AND approver_group_id = :group_id'
        );
        $stmt->execute([
            'member_role' => $memberRole,
            'status' => $status,
            'id' => $memberId,
            'group_id' => $groupId,
        ]);

        $member = $this->findMember($groupId, $memberId);
        if ($member === null) {
            throw new RuntimeException('Approver group member could not be loaded after update.', 500);
        }

        return [
            'status' => 200,
            'data' => [
                'member' => $this->serializeMember($member),
            ],
        ];
    }

    private function validateGroupPayload(array $input): array
    {
        return [
            'name' => Validator::requiredString($input['name'] ?? null, 'name', 120),
            'code' => Validator::requiredString($input['code'] ?? null, 'code', 50),
            'description' => Validator::optionalString($input['description'] ?? null, 'description', 255),
            'status' => Validator::optionalEnum($input['status'] ?? null, 'status', ['active', 'inactive']) ?? 'active',
        ];
    }

    private function findGroupById(int $groupId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT ag.*,
                    COUNT(agm.id) AS active_member_count
             FROM approver_groups ag
             LEFT JOIN approver_group_members agm
                ON agm.approver_group_id = ag.id
               AND agm.status = 'active'
             WHERE ag.id = :id
             GROUP BY ag.id"
        );
        $stmt->execute(['id' => $groupId]);
        $group = $stmt->fetch();

        return is_array($group) ? $group : null;
    }

    private function findMember(int $groupId, int $memberId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT agm.*, u.full_name, u.mobile, u.role
             FROM approver_group_members agm
             INNER JOIN users u ON u.id = agm.user_id
             WHERE agm.approver_group_id = :group_id
               AND agm.id = :id"
        );
        $stmt->execute([
            'group_id' => $groupId,
            'id' => $memberId,
        ]);
        $member = $stmt->fetch();

        return is_array($member) ? $member : null;
    }

    private function assertGroupExists(int $groupId): void
    {
        if ($this->findGroupById($groupId) === null) {
            throw new RuntimeException('Approver group not found.', 404);
        }
    }

    private function serializeGroup(array $group): array
    {
        return [
            'id' => isset($group['id']) ? (int) $group['id'] : null,
            'name' => $group['name'] ?? null,
            'code' => $group['code'] ?? null,
            'description' => $group['description'] ?? null,
            'status' => $group['status'] ?? null,
            'active_member_count' => isset($group['active_member_count']) ? (int) $group['active_member_count'] : 0,
        ];
    }

    private function serializeMember(array $member): array
    {
        return [
            'id' => isset($member['id']) ? (int) $member['id'] : null,
            'user_id' => isset($member['user_id']) ? (int) $member['user_id'] : null,
            'full_name' => $member['full_name'] ?? null,
            'mobile' => $member['mobile'] ?? null,
            'role' => $member['role'] ?? null,
            'member_role' => $member['member_role'] ?? null,
            'status' => $member['status'] ?? null,
        ];
    }
}
