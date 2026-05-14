<?php

declare(strict_types=1);

final class MasterDataService extends BaseService
{
    public function listEntity(array $actor, string $entity, array $query): array
    {
        $this->requireRole($actor, ['admin', 'super_admin']);
        [$table, $serializer, $orderBy] = $this->resolveEntity($entity);

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['per_page'] ?? 20)));
        $status = $query['status'] ?? null;
        $search = Validator::optionalString($query['search'] ?? null, 'search', 100);

        $where = [];
        $params = [];
        if ($status !== null && in_array($status, ['active', 'inactive'], true)) {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($search !== null) {
            $where[] = '(name LIKE :search OR code LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $sql = 'SELECT * FROM ' . $table;
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $result = $this->fetchPaginated($sql, $params, $page, $perPage, $orderBy);
        return [
            'status' => 200,
            'data' => [
                'items' => array_map($serializer, $result['items']),
                'pagination' => $result['pagination'],
            ],
        ];
    }

    public function createEntity(array $actor, string $entity, array $input): array
    {
        $this->requireRole($actor, ['admin', 'super_admin']);
        [$table, $serializer] = $this->resolveEntity($entity);
        $payload = $this->validateEntityPayload($entity, $input);

        $columns = array_keys($payload);
        $placeholders = array_map(fn (string $key): string => ':' . $key, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s, created_at, updated_at) VALUES (%s, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        $stmt = $this->db->prepare($sql);
        $stmt->execute($payload);

        $record = $this->findById($table, (int) $this->db->lastInsertId());
        if ($record === null) {
            throw new RuntimeException('Record could not be loaded after creation.', 500);
        }

        return [
            'status' => 201,
            'data' => [
                'item' => $serializer($record),
            ],
        ];
    }

    public function getEntity(array $actor, string $entity, int $id): array
    {
        $this->requireRole($actor, ['admin', 'super_admin']);
        [$table, $serializer] = $this->resolveEntity($entity);
        $record = $this->findById($table, $id);
        if ($record === null) {
            throw new RuntimeException('Record could not be loaded after update.', 500);
        }
        if ($record === null) {
            throw new RuntimeException(ucfirst($entity) . ' not found.', 404);
        }

        return [
            'status' => 200,
            'data' => [
                'item' => $serializer($record),
            ],
        ];
    }

    public function updateEntity(array $actor, string $entity, int $id, array $input, bool $partial): array
    {
        $this->requireRole($actor, ['admin', 'super_admin']);
        [$table, $serializer] = $this->resolveEntity($entity);
        $existing = $this->findById($table, $id);
        if ($existing === null) {
            throw new RuntimeException(ucfirst($entity) . ' not found.', 404);
        }

        $payload = $partial ? array_merge($existing, $input) : $input;
        $validated = $this->validateEntityPayload($entity, $payload);

        $assignments = [];
        foreach (array_keys($validated) as $column) {
            $assignments[] = $column . ' = :' . $column;
        }

        $validated['id'] = $id;
        $sql = sprintf(
            'UPDATE %s SET %s, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            $table,
            implode(', ', $assignments)
        );
        $stmt = $this->db->prepare($sql);
        $stmt->execute($validated);

        $record = $this->findById($table, $id);

        return [
            'status' => 200,
            'data' => [
                'item' => $serializer($record),
            ],
        ];
    }

    private function resolveEntity(string $entity): array
    {
        return match ($entity) {
            'departments' => [
                'departments',
                fn (array $row): array => [
                    'id' => isset($row['id']) ? (int) $row['id'] : null,
                    'name' => $row['name'] ?? null,
                    'code' => $row['code'] ?? null,
                    'status' => $row['status'] ?? null,
                ],
                'created_at DESC',
            ],
            'designations' => [
                'designations',
                fn (array $row): array => [
                    'id' => isset($row['id']) ? (int) $row['id'] : null,
                    'name' => $row['name'] ?? null,
                    'code' => $row['code'] ?? null,
                    'status' => $row['status'] ?? null,
                ],
                'created_at DESC',
            ],
            'leave-types' => [
                'leave_types',
                fn (array $row): array => [
                    'id' => isset($row['id']) ? (int) $row['id'] : null,
                    'code' => $row['code'] ?? null,
                    'name' => $row['name'] ?? null,
                    'description' => $row['description'] ?? null,
                    'unit' => $row['unit'] ?? null,
                    'yearly_quota' => isset($row['yearly_quota']) ? (float) $row['yearly_quota'] : null,
                    'carry_forward_allowed' => isset($row['carry_forward_allowed']) ? (bool) $row['carry_forward_allowed'] : null,
                    'max_carry_forward' => isset($row['max_carry_forward']) ? (float) $row['max_carry_forward'] : null,
                    'requires_approval' => isset($row['requires_approval']) ? (bool) $row['requires_approval'] : null,
                    'allow_comp_off' => isset($row['allow_comp_off']) ? (bool) $row['allow_comp_off'] : null,
                    'status' => $row['status'] ?? null,
                ],
                'created_at DESC',
            ],
            default => throw new InvalidArgumentException('Unsupported entity.', 400),
        };
    }

    private function validateEntityPayload(string $entity, array $input): array
    {
        return match ($entity) {
            'departments', 'designations' => [
                'name' => Validator::requiredString($input['name'] ?? null, 'name', 120),
                'code' => Validator::requiredString($input['code'] ?? null, 'code', 50),
                'status' => Validator::optionalEnum($input['status'] ?? null, 'status', ['active', 'inactive']) ?? 'active',
            ],
            'leave-types' => [
                'code' => Validator::requiredString($input['code'] ?? null, 'code', 50),
                'name' => Validator::requiredString($input['name'] ?? null, 'name', 120),
                'description' => Validator::optionalString($input['description'] ?? null, 'description', 255),
                'unit' => Validator::enum($input['unit'] ?? 'day', 'unit', ['day']),
                'yearly_quota' => Validator::requiredNumeric($input['yearly_quota'] ?? 0, 'yearly_quota'),
                'carry_forward_allowed' => Validator::booleanValue($input['carry_forward_allowed'] ?? 0, 'carry_forward_allowed'),
                'max_carry_forward' => Validator::requiredNumeric($input['max_carry_forward'] ?? 0, 'max_carry_forward'),
                'requires_approval' => Validator::booleanValue($input['requires_approval'] ?? 1, 'requires_approval'),
                'allow_comp_off' => Validator::booleanValue($input['allow_comp_off'] ?? 0, 'allow_comp_off'),
                'status' => Validator::optionalEnum($input['status'] ?? null, 'status', ['active', 'inactive']) ?? 'active',
            ],
            default => throw new InvalidArgumentException('Unsupported entity.', 400),
        };
    }

    private function findById(string $table, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ' . $table . ' WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
