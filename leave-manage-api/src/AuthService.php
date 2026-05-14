<?php

declare(strict_types=1);

final class AuthService extends BaseService
{
    public function __construct(
        PDO $db,
        private readonly TokenService $tokenService
    ) {
        parent::__construct($db);
    }

    public function login(array $input): array
    {
        $mobile = Validator::requiredMobile($input['mobile'] ?? null);
        $pin = Validator::requiredPin($input['pin'] ?? null);
        $deviceUuid = Validator::optionalString($input['device_uuid'] ?? null, 'device_uuid', 191);

        $stmt = $this->db->prepare(
            "SELECT *
             FROM users
             WHERE mobile = :mobile
             LIMIT 1"
        );
        $stmt->execute(['mobile' => $mobile]);
        $user = $stmt->fetch();

        if (!is_array($user) || $user['status'] !== 'active') {
            throw new RuntimeException('Invalid mobile or PIN.', 401);
        }

        if (!password_verify($pin, (string) $user['pin_hash'])) {
            throw new RuntimeException('Invalid mobile or PIN.', 401);
        }

        $refreshToken = $this->tokenService->generateRefreshToken();
        $refreshTokenHash = $this->tokenService->refreshTokenHash($refreshToken);

        $this->db->beginTransaction();
        try {
            $update = $this->db->prepare(
                'UPDATE users
                 SET device_uuid = :device_uuid,
                     last_login_at = UTC_TIMESTAMP(),
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $update->execute([
                'device_uuid' => $deviceUuid,
                'id' => $user['id'],
            ]);

            $tokenInsert = $this->db->prepare(
                'INSERT INTO refresh_tokens (
                    user_id,
                    token_hash,
                    expires_at,
                    revoked_at,
                    created_at,
                    last_used_at
                ) VALUES (
                    :user_id,
                    :token_hash,
                    :expires_at,
                    NULL,
                    UTC_TIMESTAMP(),
                    UTC_TIMESTAMP()
                )'
            );
            $tokenInsert->execute([
                'user_id' => $user['id'],
                'token_hash' => $refreshTokenHash,
                'expires_at' => $this->tokenService->refreshTokenExpiry(),
            ]);

            $freshUser = $this->findUserById((int) $user['id'], true);
            if ($freshUser === null) {
                throw new RuntimeException('User not found after login.', 500);
            }

            $accessToken = $this->tokenService->createAccessToken($freshUser);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }

        return [
            'status' => 200,
            'data' => [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer',
                'expires_in' => $this->tokenService->accessTokenTtlMinutes() * 60,
                'user' => $this->serializeUser($freshUser),
            ],
        ];
    }

    public function refresh(?string $refreshToken): array
    {
        $token = Validator::requiredString($refreshToken, 'refresh_token', 255);
        $tokenHash = $this->tokenService->refreshTokenHash($token);

        $stmt = $this->db->prepare(
            "SELECT rt.*, u.status AS user_status
             FROM refresh_tokens rt
             INNER JOIN users u ON u.id = rt.user_id
             WHERE rt.token_hash = :token_hash
               AND rt.revoked_at IS NULL
               AND rt.expires_at >= UTC_TIMESTAMP()
             LIMIT 1"
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $stored = $stmt->fetch();

        if (!is_array($stored) || $stored['user_status'] !== 'active') {
            throw new RuntimeException('Refresh token is invalid or expired.', 401);
        }

        $user = $this->findUserById((int) $stored['user_id'], true);
        if ($user === null || $user['status'] !== 'active') {
            throw new RuntimeException('User is not active.', 401);
        }

        $update = $this->db->prepare(
            'UPDATE refresh_tokens
             SET last_used_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $update->execute(['id' => $stored['id']]);

        return [
            'status' => 200,
            'data' => [
                'access_token' => $this->tokenService->createAccessToken($user),
                'token_type' => 'Bearer',
                'expires_in' => $this->tokenService->accessTokenTtlMinutes() * 60,
            ],
        ];
    }

    public function logout(?string $refreshToken, ?array $currentUser): array
    {
        if ($refreshToken === null && $currentUser === null) {
            throw new InvalidArgumentException('Either refresh_token or bearer token is required.', 422);
        }

        if ($refreshToken !== null) {
            $tokenHash = $this->tokenService->refreshTokenHash($refreshToken);
            $stmt = $this->db->prepare(
                'UPDATE refresh_tokens
                 SET revoked_at = UTC_TIMESTAMP()
                 WHERE token_hash = :token_hash
                   AND revoked_at IS NULL'
            );
            $stmt->execute(['token_hash' => $tokenHash]);
        }

        if ($currentUser !== null) {
            $stmt = $this->db->prepare(
                'UPDATE refresh_tokens
                 SET revoked_at = UTC_TIMESTAMP()
                 WHERE user_id = :user_id
                   AND revoked_at IS NULL'
            );
            $stmt->execute(['user_id' => $currentUser['id']]);
        }

        return [
            'status' => 200,
            'data' => [
                'message' => 'Logged out successfully.',
            ],
        ];
    }

    public function me(array $user): array
    {
        return [
            'status' => 200,
            'data' => [
                'user' => $this->serializeUser($user),
            ],
        ];
    }

    public function changePin(array $actor, array $input): array
    {
        $currentPin = Validator::requiredPin($input['current_pin'] ?? null, 'current_pin');
        $newPin = Validator::requiredPin($input['new_pin'] ?? null, 'new_pin');

        if ($currentPin === $newPin) {
            throw new InvalidArgumentException('new_pin must be different from current_pin.', 422);
        }

        $user = $this->findUserById((int) $actor['id'], true);
        if ($user === null) {
            throw new RuntimeException('User not found.', 404);
        }

        if (!password_verify($currentPin, (string) $user['pin_hash'])) {
            throw new RuntimeException('Current PIN is invalid.', 401);
        }

        $stmt = $this->db->prepare(
            'UPDATE users
             SET pin_hash = :pin_hash,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $stmt->execute([
            'pin_hash' => password_hash($newPin, PASSWORD_DEFAULT),
            'id' => $actor['id'],
        ]);

        return [
            'status' => 200,
            'data' => [
                'message' => 'PIN updated successfully.',
            ],
        ];
    }

    public function authenticateBearerToken(?string $authorizationHeader): array
    {
        if ($authorizationHeader === null || !preg_match('/^Bearer\s+(.+)$/i', $authorizationHeader, $matches)) {
            throw new RuntimeException('Bearer token is required.', 401);
        }

        $payload = $this->tokenService->validateAccessToken(trim($matches[1]));
        $userId = isset($payload['sub']) ? (int) $payload['sub'] : 0;
        if ($userId <= 0) {
            throw new RuntimeException('Access token payload is invalid.', 401);
        }

        $user = $this->findUserById($userId, true);
        if ($user === null || $user['status'] !== 'active') {
            throw new RuntimeException('Authenticated user is not active.', 401);
        }

        return [
            'payload' => $payload,
            'user' => $user,
        ];
    }

    private function serializeUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'employee_code' => $user['employee_code'],
            'full_name' => $user['full_name'],
            'mobile' => $user['mobile'],
            'email' => $user['email'],
            'role' => $user['role'],
            'status' => $user['status'],
            'department' => $user['department_id'] === null ? null : [
                'id' => (int) $user['department_id'],
                'name' => $user['department_name'] ?? null,
            ],
            'designation' => $user['designation_id'] === null ? null : [
                'id' => (int) $user['designation_id'],
                'name' => $user['designation_name'] ?? null,
            ],
            'approver_group' => $user['approver_group_id'] === null ? null : [
                'id' => (int) $user['approver_group_id'],
                'name' => $user['approver_group_name'] ?? null,
            ],
            'joining_date' => $user['joining_date'],
            'device_uuid' => $user['device_uuid'],
            'last_login_at' => $user['last_login_at'],
        ];
    }
}
