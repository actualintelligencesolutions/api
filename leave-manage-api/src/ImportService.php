<?php

declare(strict_types=1);

final class ImportService extends BaseService
{
    public function importBootstrapPayload(array $actor, array $payload, string $sourceName): array
    {
        $this->requireSuperAdmin($actor);

        $normalized = $this->validatePayload($payload);
        $summary = [
            'departments' => 0,
            'designations' => 0,
            'approver_groups' => 0,
            'approver_group_members' => 0,
            'users' => 0,
            'leave_types' => 0,
            'holidays' => 0,
            'leave_balances' => 0,
        ];

        $this->db->beginTransaction();
        try {
            foreach ($normalized['departments'] as $department) {
                $this->upsertDepartment($department);
                $summary['departments']++;
            }

            foreach ($normalized['designations'] as $designation) {
                $this->upsertDesignation($designation);
                $summary['designations']++;
            }

            foreach ($normalized['approver_groups'] as $group) {
                $this->upsertApproverGroup($group);
                $summary['approver_groups']++;
            }

            foreach ($normalized['users'] as $user) {
                $this->upsertUser($user);
                $summary['users']++;
            }

            foreach ($normalized['approver_groups'] as $group) {
                $summary['approver_group_members'] += $this->syncApproverGroupMembers($group);
            }

            foreach ($normalized['leave_types'] as $leaveType) {
                $this->upsertLeaveType($leaveType);
                $summary['leave_types']++;
            }

            foreach ($normalized['holidays'] as $holiday) {
                $this->upsertHoliday($holiday);
                $summary['holidays']++;
            }

            foreach ($normalized['leave_balances'] as $balance) {
                $this->upsertLeaveBalance($balance);
                $summary['leave_balances']++;
            }

            $runId = $this->logImportRun((int) $actor['id'], $sourceName, 'success', $summary, null);
            $this->db->commit();

            return [
                'status' => 200,
                'data' => [
                    'message' => 'Bootstrap JSON imported successfully.',
                    'import_run_id' => $runId,
                    'summary' => $summary,
                ],
            ];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            try {
                $this->logImportRun((int) $actor['id'], $sourceName, 'failed', $summary, $exception->getMessage());
            } catch (Throwable) {
            }

            throw $exception;
        }
    }

    public function parseUploadedJsonFile(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('A valid JSON file upload is required.', 422);
        }

        $originalName = is_string($file['name'] ?? null) ? $file['name'] : 'upload.json';
        if (!str_ends_with(strtolower($originalName), '.json')) {
            throw new InvalidArgumentException('Only .json files are allowed.', 422);
        }

        $tmpName = $file['tmp_name'] ?? null;
        if (!is_string($tmpName) || !is_uploaded_file($tmpName)) {
            throw new InvalidArgumentException('Uploaded file is invalid.', 422);
        }

        $contents = file_get_contents($tmpName);
        if ($contents === false || trim($contents) === '') {
            throw new InvalidArgumentException('Uploaded JSON file is empty.', 422);
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Uploaded file must contain a JSON object.', 422);
        }

        return [
            'source_name' => $originalName,
            'payload' => $decoded,
        ];
    }

    private function validatePayload(array $payload): array
    {
        return [
            'departments' => $this->normalizeList($payload['departments'] ?? []),
            'designations' => $this->normalizeList($payload['designations'] ?? []),
            'approver_groups' => $this->normalizeList($payload['approver_groups'] ?? []),
            'users' => $this->normalizeList($payload['users'] ?? []),
            'leave_types' => $this->normalizeList($payload['leave_types'] ?? []),
            'holidays' => $this->normalizeList($payload['holidays'] ?? []),
            'leave_balances' => $this->normalizeList($payload['leave_balances'] ?? []),
        ];
    }

    private function normalizeList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException('Import sections must be arrays.', 422);
        }

        foreach ($value as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Every import row must be a JSON object.', 422);
            }
        }

        return $value;
    }

    private function upsertDepartment(array $department): void
    {
        $code = Validator::requiredString($department['code'] ?? null, 'department.code', 50);
        $name = Validator::requiredString($department['name'] ?? null, 'department.name', 120);
        $status = Validator::optionalEnum($department['status'] ?? null, 'department.status', ['active', 'inactive']) ?? 'active';

        $existingId = $this->lookupIdByCode('departments', $code);
        if ($existingId === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO departments (name, code, status, created_at, updated_at)
                 VALUES (:name, :code, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $stmt->execute([
                'name' => $name,
                'code' => $code,
                'status' => $status,
            ]);
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE departments
             SET name = :name,
                 status = :status,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'status' => $status,
            'id' => $existingId,
        ]);
    }

    private function upsertDesignation(array $designation): void
    {
        $code = Validator::requiredString($designation['code'] ?? null, 'designation.code', 50);
        $name = Validator::requiredString($designation['name'] ?? null, 'designation.name', 120);
        $status = Validator::optionalEnum($designation['status'] ?? null, 'designation.status', ['active', 'inactive']) ?? 'active';

        $existingId = $this->lookupIdByCode('designations', $code);
        if ($existingId === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO designations (name, code, status, created_at, updated_at)
                 VALUES (:name, :code, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $stmt->execute([
                'name' => $name,
                'code' => $code,
                'status' => $status,
            ]);
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE designations
             SET name = :name,
                 status = :status,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'status' => $status,
            'id' => $existingId,
        ]);
    }

    private function upsertApproverGroup(array $group): void
    {
        $code = Validator::requiredString($group['code'] ?? null, 'approver_group.code', 50);
        $name = Validator::requiredString($group['name'] ?? null, 'approver_group.name', 120);
        $description = Validator::optionalString($group['description'] ?? null, 'approver_group.description', 255);
        $status = Validator::optionalEnum($group['status'] ?? null, 'approver_group.status', ['active', 'inactive']) ?? 'active';

        $existingId = $this->lookupIdByCode('approver_groups', $code);
        if ($existingId === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO approver_groups (name, code, description, status, created_at, updated_at)
                 VALUES (:name, :code, :description, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $stmt->execute([
                'name' => $name,
                'code' => $code,
                'description' => $description,
                'status' => $status,
            ]);
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE approver_groups
             SET name = :name,
                 description = :description,
                 status = :status,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'description' => $description,
            'status' => $status,
            'id' => $existingId,
        ]);
    }

    private function upsertUser(array $user): void
    {
        $employeeCode = Validator::requiredString($user['employee_code'] ?? null, 'user.employee_code', 50);
        $fullName = Validator::requiredString($user['full_name'] ?? null, 'user.full_name', 150);
        $mobile = Validator::requiredMobile($user['mobile'] ?? null);
        $email = Validator::optionalString($user['email'] ?? null, 'user.email', 150);
        $role = Validator::enum($user['role'] ?? null, 'user.role', ['super_admin', 'admin', 'staff']);
        $status = Validator::optionalEnum($user['status'] ?? null, 'user.status', ['active', 'inactive']) ?? 'active';
        $joiningDate = Validator::optionalDate($user['joining_date'] ?? null, 'user.joining_date');
        $departmentCode = Validator::optionalString($user['department_code'] ?? null, 'user.department_code', 50);
        $designationCode = Validator::optionalString($user['designation_code'] ?? null, 'user.designation_code', 50);
        $approverGroupCode = Validator::optionalString($user['approver_group_code'] ?? null, 'user.approver_group_code', 50);
        $pin = Validator::optionalString($user['pin'] ?? null, 'user.pin', 20);
        $pinHash = Validator::optionalString($user['pin_hash'] ?? null, 'user.pin_hash', 255);

        if ($pin === null && $pinHash === null) {
            throw new InvalidArgumentException('Each imported user must include pin or pin_hash.', 422);
        }

        if ($pin !== null) {
            Validator::requiredPin($pin, 'user.pin');
        }

        $departmentId = $departmentCode === null ? null : $this->lookupIdByCode('departments', $departmentCode);
        $designationId = $designationCode === null ? null : $this->lookupIdByCode('designations', $designationCode);
        $approverGroupId = $approverGroupCode === null ? null : $this->lookupIdByCode('approver_groups', $approverGroupCode);

        if ($departmentCode !== null && $departmentId === null) {
            throw new InvalidArgumentException('Unknown department_code for user ' . $employeeCode . '.', 422);
        }
        if ($designationCode !== null && $designationId === null) {
            throw new InvalidArgumentException('Unknown designation_code for user ' . $employeeCode . '.', 422);
        }
        if ($approverGroupCode !== null && $approverGroupId === null) {
            throw new InvalidArgumentException('Unknown approver_group_code for user ' . $employeeCode . '.', 422);
        }

        $resolvedPinHash = $pinHash ?? password_hash((string) $pin, PASSWORD_DEFAULT);
        $existingId = $this->lookupUserIdByEmployeeCode($employeeCode);

        if ($existingId === null) {
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
                'pin_hash' => $resolvedPinHash,
                'status' => $status,
                'department_id' => $departmentId,
                'designation_id' => $designationId,
                'approver_group_id' => $approverGroupId,
                'joining_date' => $joiningDate,
            ]);
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE users
             SET full_name = :full_name,
                 mobile = :mobile,
                 email = :email,
                 role = :role,
                 pin_hash = :pin_hash,
                 status = :status,
                 department_id = :department_id,
                 designation_id = :designation_id,
                 approver_group_id = :approver_group_id,
                 joining_date = :joining_date,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $stmt->execute([
            'full_name' => $fullName,
            'mobile' => $mobile,
            'email' => $email,
            'role' => $role,
            'pin_hash' => $resolvedPinHash,
            'status' => $status,
            'department_id' => $departmentId,
            'designation_id' => $designationId,
            'approver_group_id' => $approverGroupId,
            'joining_date' => $joiningDate,
            'id' => $existingId,
        ]);
    }

    private function syncApproverGroupMembers(array $group): int
    {
        $members = $this->normalizeList($group['members'] ?? []);
        if ($members === []) {
            return 0;
        }

        $groupCode = Validator::requiredString($group['code'] ?? null, 'approver_group.code', 50);
        $groupId = $this->lookupIdByCode('approver_groups', $groupCode);
        if ($groupId === null) {
            throw new InvalidArgumentException('Unknown approver_group_code while syncing members.', 422);
        }

        $count = 0;
        foreach ($members as $member) {
            $employeeCode = Validator::requiredString($member['employee_code'] ?? null, 'approver_group_member.employee_code', 50);
            $memberRole = Validator::optionalEnum($member['member_role'] ?? null, 'approver_group_member.member_role', ['member', 'primary']) ?? 'member';
            $status = Validator::optionalEnum($member['status'] ?? null, 'approver_group_member.status', ['active', 'inactive']) ?? 'active';
            $userId = $this->lookupUserIdByEmployeeCode($employeeCode);

            if ($userId === null) {
                throw new InvalidArgumentException('Unknown employee_code in approver group members: ' . $employeeCode . '.', 422);
            }

            $userRole = $this->lookupUserRoleById($userId);
            if (!in_array($userRole, ['admin', 'super_admin'], true)) {
                throw new InvalidArgumentException(
                    'Approver group member ' . $employeeCode . ' must be an admin or super_admin.',
                    422
                );
            }

            $stmt = $this->db->prepare(
                'SELECT id FROM approver_group_members
                 WHERE approver_group_id = :approver_group_id
                   AND user_id = :user_id
                 LIMIT 1'
            );
            $stmt->execute([
                'approver_group_id' => $groupId,
                'user_id' => $userId,
            ]);
            $existingId = $stmt->fetchColumn();

            if ($existingId === false) {
                $insert = $this->db->prepare(
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
                $insert->execute([
                    'approver_group_id' => $groupId,
                    'user_id' => $userId,
                    'member_role' => $memberRole,
                    'status' => $status,
                ]);
                $count++;
                continue;
            }

            $update = $this->db->prepare(
                'UPDATE approver_group_members
                 SET member_role = :member_role,
                     status = :status,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $update->execute([
                'member_role' => $memberRole,
                'status' => $status,
                'id' => $existingId,
            ]);
            $count++;
        }

        return $count;
    }

    private function upsertLeaveType(array $leaveType): void
    {
        $code = Validator::requiredString($leaveType['code'] ?? null, 'leave_type.code', 50);
        $name = Validator::requiredString($leaveType['name'] ?? null, 'leave_type.name', 120);
        $description = Validator::optionalString($leaveType['description'] ?? null, 'leave_type.description', 255);
        $unit = Validator::enum($leaveType['unit'] ?? 'day', 'leave_type.unit', ['day']);
        $yearlyQuota = Validator::requiredNumeric($leaveType['yearly_quota'] ?? 0, 'leave_type.yearly_quota');
        $carryForwardAllowed = Validator::booleanValue($leaveType['carry_forward_allowed'] ?? 0, 'leave_type.carry_forward_allowed');
        $maxCarryForward = Validator::requiredNumeric($leaveType['max_carry_forward'] ?? 0, 'leave_type.max_carry_forward');
        $requiresApproval = Validator::booleanValue($leaveType['requires_approval'] ?? 1, 'leave_type.requires_approval');
        $allowCompOff = Validator::booleanValue($leaveType['allow_comp_off'] ?? 0, 'leave_type.allow_comp_off');
        $status = Validator::optionalEnum($leaveType['status'] ?? null, 'leave_type.status', ['active', 'inactive']) ?? 'active';

        $existingId = $this->lookupIdByCode('leave_types', $code);
        if ($existingId === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO leave_types (
                    code,
                    name,
                    description,
                    unit,
                    yearly_quota,
                    carry_forward_allowed,
                    max_carry_forward,
                    requires_approval,
                    allow_comp_off,
                    status,
                    created_at,
                    updated_at
                ) VALUES (
                    :code,
                    :name,
                    :description,
                    :unit,
                    :yearly_quota,
                    :carry_forward_allowed,
                    :max_carry_forward,
                    :requires_approval,
                    :allow_comp_off,
                    :status,
                    UTC_TIMESTAMP(),
                    UTC_TIMESTAMP()
                )'
            );
            $stmt->execute([
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'unit' => $unit,
                'yearly_quota' => $yearlyQuota,
                'carry_forward_allowed' => $carryForwardAllowed,
                'max_carry_forward' => $maxCarryForward,
                'requires_approval' => $requiresApproval,
                'allow_comp_off' => $allowCompOff,
                'status' => $status,
            ]);
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE leave_types
             SET name = :name,
                 description = :description,
                 unit = :unit,
                 yearly_quota = :yearly_quota,
                 carry_forward_allowed = :carry_forward_allowed,
                 max_carry_forward = :max_carry_forward,
                 requires_approval = :requires_approval,
                 allow_comp_off = :allow_comp_off,
                 status = :status,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'description' => $description,
            'unit' => $unit,
            'yearly_quota' => $yearlyQuota,
            'carry_forward_allowed' => $carryForwardAllowed,
            'max_carry_forward' => $maxCarryForward,
            'requires_approval' => $requiresApproval,
            'allow_comp_off' => $allowCompOff,
            'status' => $status,
            'id' => $existingId,
        ]);
    }

    private function upsertHoliday(array $holiday): void
    {
        $holidayDate = Validator::requiredDate($holiday['holiday_date'] ?? null, 'holiday.holiday_date');
        $name = Validator::requiredString($holiday['name'] ?? null, 'holiday.name', 150);
        $holidayType = Validator::requiredString($holiday['holiday_type'] ?? 'public', 'holiday.holiday_type', 50);
        $locationCode = Validator::optionalString($holiday['location_code'] ?? null, 'holiday.location_code', 50);
        $isOptional = Validator::booleanValue($holiday['is_optional'] ?? 0, 'holiday.is_optional');

        $stmt = $this->db->prepare(
            'SELECT id FROM holidays
             WHERE holiday_date = :holiday_date
               AND name = :name
               AND ((location_code IS NULL AND :location_code_null = 1) OR location_code = :location_code)
             LIMIT 1'
        );
        $stmt->execute([
            'holiday_date' => $holidayDate,
            'name' => $name,
            'location_code_null' => $locationCode === null ? 1 : 0,
            'location_code' => $locationCode,
        ]);
        $existingId = $stmt->fetchColumn();

        if ($existingId === false) {
            $insert = $this->db->prepare(
                'INSERT INTO holidays (
                    holiday_date,
                    name,
                    holiday_type,
                    location_code,
                    is_optional,
                    created_at,
                    updated_at
                ) VALUES (
                    :holiday_date,
                    :name,
                    :holiday_type,
                    :location_code,
                    :is_optional,
                    UTC_TIMESTAMP(),
                    UTC_TIMESTAMP()
                )'
            );
            $insert->execute([
                'holiday_date' => $holidayDate,
                'name' => $name,
                'holiday_type' => $holidayType,
                'location_code' => $locationCode,
                'is_optional' => $isOptional,
            ]);
            return;
        }

        $update = $this->db->prepare(
            'UPDATE holidays
             SET holiday_type = :holiday_type,
                 is_optional = :is_optional,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $update->execute([
            'holiday_type' => $holidayType,
            'is_optional' => $isOptional,
            'id' => $existingId,
        ]);
    }

    private function upsertLeaveBalance(array $balance): void
    {
        $employeeCode = Validator::requiredString($balance['employee_code'] ?? null, 'leave_balance.employee_code', 50);
        $leaveTypeCode = Validator::requiredString($balance['leave_type_code'] ?? null, 'leave_balance.leave_type_code', 50);
        $periodYear = Validator::requiredInt($balance['period_year'] ?? null, 'leave_balance.period_year');
        $openingBalance = Validator::requiredNumeric($balance['opening_balance'] ?? 0, 'leave_balance.opening_balance');
        $creditedBalance = Validator::requiredNumeric($balance['credited_balance'] ?? 0, 'leave_balance.credited_balance');
        $usedBalance = Validator::requiredNumeric($balance['used_balance'] ?? 0, 'leave_balance.used_balance');
        $pendingBalance = Validator::requiredNumeric($balance['pending_balance'] ?? 0, 'leave_balance.pending_balance');

        $userId = $this->lookupUserIdByEmployeeCode($employeeCode);
        $leaveTypeId = $this->lookupIdByCode('leave_types', $leaveTypeCode);

        if ($userId === null) {
            throw new InvalidArgumentException('Unknown employee_code in leave_balances: ' . $employeeCode . '.', 422);
        }
        if ($leaveTypeId === null) {
            throw new InvalidArgumentException('Unknown leave_type_code in leave_balances: ' . $leaveTypeCode . '.', 422);
        }

        $availableBalance = round($openingBalance + $creditedBalance - $usedBalance - $pendingBalance, 2);

        $stmt = $this->db->prepare(
            'SELECT id FROM user_leave_balances
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
        $existingId = $stmt->fetchColumn();

        if ($existingId === false) {
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
                    :opening_balance,
                    :credited_balance,
                    :used_balance,
                    :pending_balance,
                    :available_balance,
                    UTC_TIMESTAMP()
                )'
            );
            $insert->execute([
                'user_id' => $userId,
                'leave_type_id' => $leaveTypeId,
                'period_year' => $periodYear,
                'opening_balance' => $openingBalance,
                'credited_balance' => $creditedBalance,
                'used_balance' => $usedBalance,
                'pending_balance' => $pendingBalance,
                'available_balance' => $availableBalance,
            ]);
            return;
        }

        $update = $this->db->prepare(
            'UPDATE user_leave_balances
             SET opening_balance = :opening_balance,
                 credited_balance = :credited_balance,
                 used_balance = :used_balance,
                 pending_balance = :pending_balance,
                 available_balance = :available_balance,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $update->execute([
            'opening_balance' => $openingBalance,
            'credited_balance' => $creditedBalance,
            'used_balance' => $usedBalance,
            'pending_balance' => $pendingBalance,
            'available_balance' => $availableBalance,
            'id' => $existingId,
        ]);
    }

    private function lookupIdByCode(string $table, string $code): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM ' . $table . ' WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function lookupUserIdByEmployeeCode(string $employeeCode): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM users WHERE employee_code = :employee_code LIMIT 1');
        $stmt->execute(['employee_code' => $employeeCode]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function lookupUserRoleById(int $userId): ?string
    {
        $stmt = $this->db->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $role = $stmt->fetchColumn();

        return $role === false ? null : (string) $role;
    }

    private function logImportRun(int $actorId, string $sourceName, string $status, array $summary, ?string $errorMessage): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO import_runs (
                imported_by_user_id,
                source_name,
                import_type,
                status,
                summary_json,
                error_message,
                created_at
            ) VALUES (
                :imported_by_user_id,
                :source_name,
                :import_type,
                :status,
                :summary_json,
                :error_message,
                UTC_TIMESTAMP()
            )'
        );
        $stmt->execute([
            'imported_by_user_id' => $actorId,
            'source_name' => $sourceName,
            'import_type' => 'json_bootstrap',
            'status' => $status,
            'summary_json' => json_encode($summary, JSON_UNESCAPED_SLASHES),
            'error_message' => $errorMessage,
        ]);

        return (int) $this->db->lastInsertId();
    }
}
