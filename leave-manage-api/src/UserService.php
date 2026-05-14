<?php

declare(strict_types=1);

final class UserService extends BaseService
{
    public function myProfile(array $actor): array
    {
        $user = $this->findUserById((int) $actor['id'], true);
        if ($user === null) {
            throw new RuntimeException('User not found.', 404);
        }

        return [
            'status' => 200,
            'data' => [
                'user' => $this->serializeUser($user),
            ],
        ];
    }

    public function list(array $actor, array $query): array
    {
        $this->requireRole($actor, ['super_admin']);

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['per_page'] ?? 20)));
        $status = $this->normalizeStatus($query['status'] ?? null, ['active', 'inactive']);
        $role = Validator::optionalEnum($query['role'] ?? null, 'role', ['super_admin', 'admin', 'staff']);
        $search = Validator::optionalString($query['search'] ?? null, 'search', 100);

        $where = [];
        $params = [];

        if ($status !== null) {
            $where[] = 'u.status = :status';
            $params['status'] = $status;
        }

        if ($role !== null) {
            $where[] = 'u.role = :role';
            $params['role'] = $role;
        }

        if ($search !== null) {
            $where[] = '(u.full_name LIKE :search OR u.mobile LIKE :search OR u.employee_code LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $sql = 'SELECT u.*,
                       d.name AS department_name,
                       g.name AS designation_name,
                       ag.name AS approver_group_name
                FROM users u
                LEFT JOIN departments d ON d.id = u.department_id
                LEFT JOIN designations g ON g.id = u.designation_id
                LEFT JOIN approver_groups ag ON ag.id = u.approver_group_id';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $result = $this->fetchPaginated($sql, $params, $page, $perPage, 'u.created_at DESC');

        return [
            'status' => 200,
            'data' => [
                'items' => array_map(fn (array $item): array => $this->serializeUser($item), $result['items']),
                'pagination' => $result['pagination'],
            ],
        ];
    }

    public function create(array $actor, array $input): array
    {
        $this->requireSuperAdmin($actor);

        $employeeCode = Validator::requiredString($input['employee_code'] ?? null, 'employee_code', 50);
        $fullName = Validator::requiredString($input['full_name'] ?? null, 'full_name', 150);
        $mobile = Validator::requiredMobile($input['mobile'] ?? null);
        $email = Validator::optionalString($input['email'] ?? null, 'email', 150);
        $role = Validator::enum($input['role'] ?? null, 'role', ['super_admin', 'admin', 'staff']);
        $pin = Validator::requiredPin($input['pin'] ?? null);
        $status = Validator::optionalEnum($input['status'] ?? null, 'status', ['active', 'inactive']) ?? 'active';
        $departmentId = Validator::optionalInt($input['department_id'] ?? null, 'department_id');
        $designationId = Validator::optionalInt($input['designation_id'] ?? null, 'designation_id');
        $approverGroupId = Validator::optionalInt($input['approver_group_id'] ?? null, 'approver_group_id');
        $joiningDate = Validator::optionalDate($input['joining_date'] ?? null, 'joining_date');

        $this->assertReferenceExists('departments', $departmentId);
        $this->assertReferenceExists('designations', $designationId);
        $this->assertReferenceExists('approver_groups', $approverGroupId, "status = 'active'");

        $stmt = $this->db->prepare(
            'INSERT INTO users (
                employee_code,
                full_name,
                mobile,
                email,
                role,
                pin_hash,
                status,
                department_id,
                designation_id,
                approver_group_id,
                joining_date,
                created_at,
                updated_at
            ) VALUES (
                :employee_code,
                :full_name,
                :mobile,
                :email,
                :role,
                :pin_hash,
                :status,
                :department_id,
                :designation_id,
                :approver_group_id,
                :joining_date,
                UTC_TIMESTAMP(),
                UTC_TIMESTAMP()
            )'
        );
        $stmt->execute([
            'employee_code' => $employeeCode,
            'full_name' => $fullName,
            'mobile' => $mobile,
            'email' => $email,
            'role' => $role,
            'pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
            'status' => $status,
            'department_id' => $departmentId,
            'designation_id' => $designationId,
            'approver_group_id' => $approverGroupId,
            'joining_date' => $joiningDate,
        ]);

        $user = $this->findUserById((int) $this->db->lastInsertId(), true);
        if ($user === null) {
            throw new RuntimeException('User could not be loaded after creation.', 500);
        }

        return [
            'status' => 201,
            'data' => [
                'user' => $this->serializeUser($user),
            ],
        ];
    }

    public function get(array $actor, int $userId): array
    {
        $this->requireSuperAdmin($actor);
        $user = $this->findUserById($userId, true);
        if ($user === null) {
            throw new RuntimeException('User could not be loaded after update.', 500);
        }
        if ($user === null) {
            throw new RuntimeException('User not found.', 404);
        }

        return [
            'status' => 200,
            'data' => [
                'user' => $this->serializeUser($user),
            ],
        ];
    }

    public function update(array $actor, int $userId, array $input, bool $partial): array
    {
        $this->requireSuperAdmin($actor);
        $existing = $this->findUserById($userId, true);
        if ($existing === null) {
            throw new RuntimeException('User not found.', 404);
        }

        $payload = $partial ? $existing : [];
        foreach ($input as $key => $value) {
            $payload[$key] = $value;
        }

        $employeeCode = Validator::requiredString($payload['employee_code'] ?? null, 'employee_code', 50);
        $fullName = Validator::requiredString($payload['full_name'] ?? null, 'full_name', 150);
        $mobile = Validator::requiredMobile($payload['mobile'] ?? null);
        $email = Validator::optionalString($payload['email'] ?? null, 'email', 150);
        $role = Validator::enum($payload['role'] ?? null, 'role', ['super_admin', 'admin', 'staff']);
        $status = Validator::optionalEnum($payload['status'] ?? null, 'status', ['active', 'inactive']) ?? 'active';
        $departmentId = Validator::optionalInt($payload['department_id'] ?? null, 'department_id');
        $designationId = Validator::optionalInt($payload['designation_id'] ?? null, 'designation_id');
        $approverGroupId = Validator::optionalInt($payload['approver_group_id'] ?? null, 'approver_group_id');
        $joiningDate = Validator::optionalDate($payload['joining_date'] ?? null, 'joining_date');
        $pin = Validator::optionalString($input['pin'] ?? null, 'pin', 20);

        $this->assertReferenceExists('departments', $departmentId);
        $this->assertReferenceExists('designations', $designationId);
        $this->assertReferenceExists('approver_groups', $approverGroupId, "status = 'active'");

        $fields = [
            'employee_code' => $employeeCode,
            'full_name' => $fullName,
            'mobile' => $mobile,
            'email' => $email,
            'role' => $role,
            'status' => $status,
            'department_id' => $departmentId,
            'designation_id' => $designationId,
            'approver_group_id' => $approverGroupId,
            'joining_date' => $joiningDate,
            'id' => $userId,
        ];

        $sql = 'UPDATE users
                SET employee_code = :employee_code,
                    full_name = :full_name,
                    mobile = :mobile,
                    email = :email,
                    role = :role,
                    status = :status,
                    department_id = :department_id,
                    designation_id = :designation_id,
                    approver_group_id = :approver_group_id,
                    joining_date = :joining_date,
                    updated_at = UTC_TIMESTAMP()';

        if ($pin !== null && $pin !== '') {
            Validator::requiredPin($pin);
            $sql .= ', pin_hash = :pin_hash';
            $fields['pin_hash'] = password_hash($pin, PASSWORD_DEFAULT);
        }

        $sql .= ' WHERE id = :id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($fields);

        $user = $this->findUserById($userId, true);

        return [
            'status' => 200,
            'data' => [
                'user' => $this->serializeUser($user),
            ],
        ];
    }

    private function assertReferenceExists(string $table, ?int $id, ?string $extraCondition = null): void
    {
        if ($id === null) {
            return;
        }

        $sql = 'SELECT id FROM ' . $table . ' WHERE id = :id';
        if ($extraCondition !== null) {
            $sql .= ' AND ' . $extraCondition;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException($table . ' reference is invalid.', 422);
        }
    }

    private function serializeUser(array $user): array
    {
        return [
            'id' => isset($user['id']) ? (int) $user['id'] : null,
            'employee_code' => $user['employee_code'] ?? null,
            'full_name' => $user['full_name'] ?? null,
            'mobile' => $user['mobile'] ?? null,
            'email' => $user['email'] ?? null,
            'role' => $user['role'] ?? null,
            'status' => $user['status'] ?? null,
            'department' => isset($user['department_id']) && $user['department_id'] !== null ? [
                'id' => (int) $user['department_id'],
                'name' => $user['department_name'] ?? null,
            ] : null,
            'designation' => isset($user['designation_id']) && $user['designation_id'] !== null ? [
                'id' => (int) $user['designation_id'],
                'name' => $user['designation_name'] ?? null,
            ] : null,
            'approver_group' => isset($user['approver_group_id']) && $user['approver_group_id'] !== null ? [
                'id' => (int) $user['approver_group_id'],
                'name' => $user['approver_group_name'] ?? null,
            ] : null,
            'joining_date' => $user['joining_date'] ?? null,
            'device_uuid' => $user['device_uuid'] ?? null,
            'last_login_at' => $user['last_login_at'] ?? null,
        ];
    }
}
