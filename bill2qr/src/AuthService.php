<?php

declare(strict_types=1);

final class AuthService
{
    public function __construct(
        private readonly PDO $db,
        private readonly TokenService $tokenService
    ) {
    }

    public function register(array $input): array
    {
        $deviceUuid = $this->validateDeviceUuid($input['device_uuid'] ?? null);
        $deviceName = $this->validateRequiredString($input['device_name'] ?? null, 'device_name', 100);
        $platform = $this->normalizeNullableString($input['platform'] ?? null, 50);
        $upiId = $this->validateUpiId($input['upi_id'] ?? null);
        $recoveryPhone = $this->validateRecoveryPhone($input['recovery_phone'] ?? null);
        $ownerPin = $this->validatePin($input['owner_pin'] ?? null, 'owner_pin');
        $ownerPinHash = $this->hashPin($ownerPin);

        $this->db->beginTransaction();

        try {
            $existing = $this->findDeviceByUuid($deviceUuid, true);

            if ($existing !== null) {
                $update = $this->db->prepare(
                    'UPDATE devices
                     SET device_name = :device_name,
                         platform = :platform,
                         upi_id = :upi_id,
                         recovery_phone = :recovery_phone,
                         owner_pin_hash = :owner_pin_hash,
                         is_active = 1,
                         updated_at = UTC_TIMESTAMP()
                     WHERE id = :id'
                );
                $update->execute([
                    'device_name' => $deviceName,
                    'platform' => $platform,
                    'upi_id' => $upiId,
                    'recovery_phone' => $recoveryPhone,
                    'owner_pin_hash' => $ownerPinHash,
                    'id' => $existing['id'],
                ]);

                $this->revokeAllRefreshTokensForDevice((int) $existing['id']);
                $deviceId = (int) $existing['id'];
                $status = 200;
            } else {
                $insert = $this->db->prepare(
                    'INSERT INTO devices (
                        device_uuid,
                        device_name,
                        platform,
                        upi_id,
                        recovery_phone,
                        owner_pin_hash,
                        is_active,
                        created_at,
                        updated_at
                    ) VALUES (
                        :device_uuid,
                        :device_name,
                        :platform,
                        :upi_id,
                        :recovery_phone,
                        :owner_pin_hash,
                        1,
                        UTC_TIMESTAMP(),
                        UTC_TIMESTAMP()
                    )'
                );
                $insert->execute([
                    'device_uuid' => $deviceUuid,
                    'device_name' => $deviceName,
                    'platform' => $platform,
                    'upi_id' => $upiId,
                    'recovery_phone' => $recoveryPhone,
                    'owner_pin_hash' => $ownerPinHash,
                ]);

                $deviceId = (int) $this->db->lastInsertId();
                $status = 201;
            }

            $device = $this->findDeviceById($deviceId, true);
            if ($device === null) {
                throw new RuntimeException('Device registration failed.', 500);
            }

            $tokenBundle = $this->issueTokenBundle($device);
            $this->db->commit();

            return [
                'status' => $status,
                'data' => [
                    'device' => $this->serializeDevice($device),
                    'tokens' => $tokenBundle,
                ],
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    public function updateUpi(array $input): array
    {
        $deviceUuid = $this->validateDeviceUuid($input['device_uuid'] ?? null);
        $ownerPin = $this->validatePin($input['owner_pin'] ?? null, 'owner_pin');
        $newUpiId = $this->validateUpiId($input['new_upi_id'] ?? null, 'new_upi_id');

        $this->db->beginTransaction();

        try {
            $device = $this->findActiveDeviceByUuid($deviceUuid, true);
            if ($device === null) {
                throw new InvalidArgumentException('Device not found.', 404);
            }

            if (!password_verify($ownerPin, (string) $device['owner_pin_hash'])) {
                throw new InvalidArgumentException('Invalid owner PIN.', 401);
            }

            if (hash_equals((string) $device['upi_id'], $newUpiId)) {
                throw new InvalidArgumentException('new_upi_id must be different from current UPI ID.', 422);
            }

            $update = $this->db->prepare(
                'UPDATE devices
                 SET upi_id = :upi_id,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $update->execute([
                'upi_id' => $newUpiId,
                'id' => $device['id'],
            ]);

            $freshDevice = $this->findDeviceById((int) $device['id'], true);
            if ($freshDevice === null) {
                throw new RuntimeException('Device not found after UPI update.', 500);
            }

            $this->db->commit();

            return [
                'status' => 200,
                'data' => [
                    'message' => 'UPI ID updated successfully.',
                    'device' => $this->serializeDevice($freshDevice),
                ],
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    public function login(array $input): array
    {
        $deviceUuid = $this->validateDeviceUuid($input['device_uuid'] ?? null);
        $pin = $this->validatePin($input['pin'] ?? null);
        $device = $this->findActiveDeviceByUuid($deviceUuid);

        if ($device === null || !password_verify($pin, (string) $device['owner_pin_hash'])) {
            throw new InvalidArgumentException('Invalid device UUID or owner PIN.', 401);
        }

        $this->db->beginTransaction();

        try {
            $touch = $this->db->prepare(
                'UPDATE devices
                 SET last_login_at = UTC_TIMESTAMP(),
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $touch->execute(['id' => $device['id']]);

            $freshDevice = $this->findDeviceById((int) $device['id'], true);
            $tokenBundle = $this->issueTokenBundle($freshDevice ?? $device);

            $this->db->commit();

            return [
                'status' => 200,
                'data' => [
                    'device' => $this->serializeDevice($freshDevice ?? $device),
                    'tokens' => $tokenBundle,
                ],
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    public function refresh(?string $refreshToken): array
    {
        $refreshToken = $this->requireRefreshToken($refreshToken);
        $refreshTokenHash = $this->tokenService->refreshTokenHash($refreshToken);

        $this->db->beginTransaction();

        try {
            $refreshRecord = $this->findActiveRefreshToken($refreshTokenHash, true);
            if ($refreshRecord === null) {
                throw new InvalidArgumentException('Refresh token is invalid or expired.', 401);
            }

            $device = $this->findDeviceById((int) $refreshRecord['device_id'], true);
            if ($device === null || (int) $device['is_active'] !== 1) {
                throw new InvalidArgumentException('Device is not active.', 401);
            }

            $revoke = $this->db->prepare(
                'UPDATE refresh_tokens
                 SET revoked_at = UTC_TIMESTAMP(),
                     last_used_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $revoke->execute(['id' => $refreshRecord['id']]);

            $tokenBundle = $this->issueTokenBundle($device);
            $this->db->commit();

            return [
                'status' => 200,
                'data' => [
                    'device' => $this->serializeDevice($device),
                    'tokens' => $tokenBundle,
                ],
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    public function resetPin(array $input): array
    {
        $deviceUuid = $this->validateDeviceUuid($input['device_uuid'] ?? null);
        $newPin = $this->validatePin($input['new_pin'] ?? null, 'new_pin');
        $confirmPin = $this->validatePin($input['confirm_pin'] ?? null, 'confirm_pin');

        if (!hash_equals($newPin, $confirmPin)) {
            throw new InvalidArgumentException('confirm_pin must match new_pin.', 422);
        }

        $newPinHash = $this->hashPin($newPin);

        $this->db->beginTransaction();

        try {
            $device = $this->findActiveDeviceByUuid($deviceUuid, true);
            if ($device === null) {
                throw new InvalidArgumentException('Registered device not found.', 404);
            }

            $update = $this->db->prepare(
                'UPDATE devices
                 SET owner_pin_hash = :owner_pin_hash,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $update->execute([
                'owner_pin_hash' => $newPinHash,
                'id' => $device['id'],
            ]);

            $this->revokeAllRefreshTokensForDevice((int) $device['id']);
            $freshDevice = $this->findDeviceById((int) $device['id'], true);
            if ($freshDevice === null) {
                throw new RuntimeException('Device not found after PIN reset.', 500);
            }

            $tokenBundle = $this->issueTokenBundle($freshDevice);
            $this->db->commit();

            return [
                'status' => 200,
                'data' => [
                    'message' => 'Owner PIN reset successful for registered device.',
                    'device' => $this->serializeDevice($freshDevice),
                    'tokens' => $tokenBundle,
                ],
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    public function logout(?string $refreshToken, ?array $currentDevice = null): array
    {
        if ($refreshToken !== null && trim($refreshToken) !== '') {
            $refreshTokenHash = $this->tokenService->refreshTokenHash($refreshToken);
            $statement = $this->db->prepare(
                'UPDATE refresh_tokens
                 SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()),
                     last_used_at = UTC_TIMESTAMP()
                 WHERE token_hash = :token_hash
                   AND revoked_at IS NULL'
            );
            $statement->execute(['token_hash' => $refreshTokenHash]);
        } elseif ($currentDevice !== null) {
            $this->revokeAllRefreshTokensForDevice((int) $currentDevice['id']);
        } else {
            throw new InvalidArgumentException('Refresh token or bearer token is required for logout.', 400);
        }

        return [
            'status' => 200,
            'data' => [
                'message' => 'Logged out successfully.',
            ],
        ];
    }

    public function me(array $accessPayload): array
    {
        $deviceId = (int) ($accessPayload['sub'] ?? 0);
        $device = $this->findDeviceById($deviceId);

        if ($device === null || (int) $device['is_active'] !== 1) {
            throw new InvalidArgumentException('Authenticated device not found.', 401);
        }

        return [
            'status' => 200,
            'data' => [
                'device' => $this->serializeDevice($device),
            ],
        ];
    }

    public function authenticateBearerToken(?string $authorizationHeader): array
    {
        if ($authorizationHeader === null || !preg_match('/^Bearer\s+(.+)$/i', $authorizationHeader, $matches)) {
            throw new InvalidArgumentException('Bearer access token is required.', 401);
        }

        $payload = $this->tokenService->validateAccessToken(trim($matches[1]));
        $device = $this->findDeviceById((int) ($payload['sub'] ?? 0));

        if ($device === null || (int) $device['is_active'] !== 1) {
            throw new InvalidArgumentException('Authenticated device not found.', 401);
        }

        return [
            'payload' => $payload,
            'device' => $device,
        ];
    }

    private function issueTokenBundle(array $device): array
    {
        $accessToken = $this->tokenService->createAccessToken($device);
        $refreshToken = $this->tokenService->generateRefreshToken();

        $insert = $this->db->prepare(
            'INSERT INTO refresh_tokens (
                device_id,
                token_hash,
                expires_at,
                created_at,
                last_used_at
            ) VALUES (
                :device_id,
                :token_hash,
                :expires_at,
                UTC_TIMESTAMP(),
                UTC_TIMESTAMP()
            )'
        );
        $insert->execute([
            'device_id' => $device['id'],
            'token_hash' => $this->tokenService->refreshTokenHash($refreshToken),
            'expires_at' => $this->tokenService->refreshTokenExpiry(),
        ]);

        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->tokenService->accessTokenTtlMinutes() * 60,
            'refresh_token' => $refreshToken,
        ];
    }

    private function revokeAllRefreshTokensForDevice(int $deviceId): void
    {
        $statement = $this->db->prepare(
            'UPDATE refresh_tokens
             SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()),
                 last_used_at = UTC_TIMESTAMP()
             WHERE device_id = :device_id
               AND revoked_at IS NULL'
        );
        $statement->execute(['device_id' => $deviceId]);
    }

    private function findActiveDeviceByUuid(string $deviceUuid, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT *
                FROM devices
                WHERE device_uuid = :device_uuid
                  AND is_active = 1
                LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->db->prepare($sql);
        $statement->execute(['device_uuid' => $deviceUuid]);
        $device = $statement->fetch();

        return is_array($device) ? $device : null;
    }

    private function findDeviceByUuid(string $deviceUuid, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM devices WHERE device_uuid = :device_uuid LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->db->prepare($sql);
        $statement->execute(['device_uuid' => $deviceUuid]);
        $device = $statement->fetch();

        return is_array($device) ? $device : null;
    }

    private function findDeviceById(int $deviceId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM devices WHERE id = :id LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->db->prepare($sql);
        $statement->execute(['id' => $deviceId]);
        $device = $statement->fetch();

        return is_array($device) ? $device : null;
    }

    private function findActiveRefreshToken(string $tokenHash, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT *
                FROM refresh_tokens
                WHERE token_hash = :token_hash
                  AND revoked_at IS NULL
                  AND expires_at > UTC_TIMESTAMP()
                LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->db->prepare($sql);
        $statement->execute(['token_hash' => $tokenHash]);
        $token = $statement->fetch();

        return is_array($token) ? $token : null;
    }

    private function validateDeviceUuid(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('device_uuid is required.', 422);
        }

        $value = trim($value);
        if ($value === '' || strlen($value) > 191) {
            throw new InvalidArgumentException('device_uuid must be between 1 and 191 characters.', 422);
        }

        return $value;
    }

    private function validatePin(mixed $value, string $field = 'pin'): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            throw new InvalidArgumentException($field . ' is required.', 422);
        }

        $pin = trim((string) $value);
        $length = strlen($pin);

        if ($length < 4 || $length > 8) {
            throw new InvalidArgumentException($field . ' must be between 4 and 8 characters.', 422);
        }

        return $pin;
    }

    private function validateUpiId(mixed $value, string $field = 'upi_id'): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($field . ' is required.', 422);
        }

        $upiId = strtolower(trim($value));
        if ($upiId === '' || strlen($upiId) > 100) {
            throw new InvalidArgumentException($field . ' must be between 1 and 100 characters.', 422);
        }

        if (!preg_match('/^[a-z0-9.\-_]{2,}@[a-z]{2,}$/', $upiId)) {
            throw new InvalidArgumentException($field . ' must be a valid UPI ID.', 422);
        }

        return $upiId;
    }

    private function validateRecoveryPhone(mixed $value, string $field = 'recovery_phone'): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            throw new InvalidArgumentException($field . ' is required.', 422);
        }

        $phone = preg_replace('/\D+/', '', (string) $value);
        if (!is_string($phone) || strlen($phone) < 10 || strlen($phone) > 15) {
            throw new InvalidArgumentException($field . ' must be a valid phone number.', 422);
        }

        return $phone;
    }

    private function validateRequiredString(mixed $value, string $field, int $maxLength): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($field . ' is required.', 422);
        }

        $normalized = trim($value);
        if ($normalized === '' || strlen($normalized) > $maxLength) {
            throw new InvalidArgumentException($field . ' must be between 1 and ' . $maxLength . ' characters.', 422);
        }

        return $normalized;
    }

    private function normalizeNullableString(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        return substr($normalized, 0, $maxLength);
    }

    private function requireRefreshToken(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            throw new InvalidArgumentException('refresh_token is required.', 422);
        }

        return trim($value);
    }

    private function hashPin(string $pin): string
    {
        $hash = password_hash($pin, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('Unable to hash PIN.', 500);
        }

        return $hash;
    }

    private function serializeDevice(array $device): array
    {
        return [
            'id' => (int) $device['id'],
            'device_uuid' => $device['device_uuid'],
            'device_name' => $device['device_name'],
            'platform' => $device['platform'],
            'upi_id' => $device['upi_id'],
            'recovery_phone' => $device['recovery_phone'],
            'is_active' => (bool) $device['is_active'],
            'created_at' => $device['created_at'],
            'updated_at' => $device['updated_at'],
            'last_login_at' => $device['last_login_at'],
        ];
    }
}
