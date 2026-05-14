<?php

declare(strict_types=1);

final class HolidayService extends BaseService
{
    public function listForSelf(array $actor, array $query): array
    {
        $this->assertSameUserOrPrivileged($actor, (int) $actor['id']);
        return $this->list($actor, $query);
    }

    public function list(array $actor, array $query): array
    {
        $this->requireRole($actor, ['admin', 'super_admin', 'staff']);

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['per_page'] ?? 50)));
        $year = Validator::optionalInt($query['year'] ?? null, 'year');
        $locationCode = Validator::optionalString($query['location_code'] ?? null, 'location_code', 50);

        $where = [];
        $params = [];
        if ($year !== null) {
            $where[] = 'YEAR(holiday_date) = :year';
            $params['year'] = $year;
        }
        if ($locationCode !== null) {
            $where[] = '(location_code = :location_code OR location_code IS NULL)';
            $params['location_code'] = $locationCode;
        }

        $sql = 'SELECT * FROM holidays';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $result = $this->fetchPaginated($sql, $params, $page, $perPage, 'holiday_date ASC');
        return [
            'status' => 200,
            'data' => [
                'items' => array_map([$this, 'serializeHoliday'], $result['items']),
                'pagination' => $result['pagination'],
            ],
        ];
    }

    public function create(array $actor, array $input): array
    {
        $this->requireRole($actor, ['admin', 'super_admin']);
        $payload = $this->validatePayload($input);

        $stmt = $this->db->prepare(
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
        $stmt->execute($payload);

        $holiday = $this->findHoliday((int) $this->db->lastInsertId());
        if ($holiday === null) {
            throw new RuntimeException('Holiday could not be loaded after creation.', 500);
        }

        return [
            'status' => 201,
            'data' => [
                'holiday' => $this->serializeHoliday($holiday),
            ],
        ];
    }

    public function get(array $actor, int $holidayId): array
    {
        $this->requireRole($actor, ['admin', 'super_admin']);
        $holiday = $this->findHoliday($holidayId);
        if ($holiday === null) {
            throw new RuntimeException('Holiday could not be loaded after update.', 500);
        }
        if ($holiday === null) {
            throw new RuntimeException('Holiday not found.', 404);
        }

        return [
            'status' => 200,
            'data' => [
                'holiday' => $this->serializeHoliday($holiday),
            ],
        ];
    }

    public function update(array $actor, int $holidayId, array $input, bool $partial): array
    {
        $this->requireRole($actor, ['admin', 'super_admin']);
        $existing = $this->findHoliday($holidayId);
        if ($existing === null) {
            throw new RuntimeException('Holiday not found.', 404);
        }

        $payload = $this->validatePayload($partial ? array_merge($existing, $input) : $input);
        $payload['id'] = $holidayId;

        $stmt = $this->db->prepare(
            'UPDATE holidays
             SET holiday_date = :holiday_date,
                 name = :name,
                 holiday_type = :holiday_type,
                 location_code = :location_code,
                 is_optional = :is_optional,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $stmt->execute($payload);

        $holiday = $this->findHoliday($holidayId);

        return [
            'status' => 200,
            'data' => [
                'holiday' => $this->serializeHoliday($holiday),
            ],
        ];
    }

    private function validatePayload(array $input): array
    {
        return [
            'holiday_date' => Validator::requiredDate($input['holiday_date'] ?? null, 'holiday_date'),
            'name' => Validator::requiredString($input['name'] ?? null, 'name', 150),
            'holiday_type' => Validator::requiredString($input['holiday_type'] ?? 'public', 'holiday_type', 50),
            'location_code' => Validator::optionalString($input['location_code'] ?? null, 'location_code', 50),
            'is_optional' => Validator::booleanValue($input['is_optional'] ?? 0, 'is_optional'),
        ];
    }

    private function findHoliday(int $holidayId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM holidays WHERE id = :id');
        $stmt->execute(['id' => $holidayId]);
        $holiday = $stmt->fetch();

        return is_array($holiday) ? $holiday : null;
    }

    private function serializeHoliday(array $holiday): array
    {
        return [
            'id' => isset($holiday['id']) ? (int) $holiday['id'] : null,
            'holiday_date' => $holiday['holiday_date'] ?? null,
            'name' => $holiday['name'] ?? null,
            'holiday_type' => $holiday['holiday_type'] ?? null,
            'location_code' => $holiday['location_code'] ?? null,
            'is_optional' => isset($holiday['is_optional']) ? (bool) $holiday['is_optional'] : null,
        ];
    }
}
