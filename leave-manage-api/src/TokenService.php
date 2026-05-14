<?php

declare(strict_types=1);

final class TokenService
{
    private string $jwtSecret;
    private string $jwtIssuer;
    private int $accessTokenTtlMinutes;
    private int $refreshTokenTtlDays;

    public function __construct()
    {
        $this->jwtSecret = env('JWT_SECRET', '');
        $this->jwtIssuer = env('JWT_ISSUER', 'leave-manage-api');
        $this->accessTokenTtlMinutes = max(1, (int) env('ACCESS_TOKEN_TTL_MINUTES', '15'));
        $this->refreshTokenTtlDays = max(1, (int) env('REFRESH_TOKEN_TTL_DAYS', '30'));

        if ($this->jwtSecret === '') {
            throw new RuntimeException('JWT_SECRET is required.', 500);
        }
    }

    public function createAccessToken(array $user): string
    {
        $issuedAt = time();
        $expiresAt = $issuedAt + ($this->accessTokenTtlMinutes * 60);

        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];

        $payload = [
            'iss' => $this->jwtIssuer,
            'sub' => (string) $user['id'],
            'role' => $user['role'],
            'mobile' => $user['mobile'],
            'device_uuid' => $user['device_uuid'] ?? null,
            'iat' => $issuedAt,
            'nbf' => $issuedAt,
            'exp' => $expiresAt,
        ];

        $encodedHeader = $this->base64UrlEncode((string) json_encode($header));
        $encodedPayload = $this->base64UrlEncode((string) json_encode($payload));
        $signature = hash_hmac(
            'sha256',
            $encodedHeader . '.' . $encodedPayload,
            $this->jwtSecret,
            true
        );

        return $encodedHeader . '.' . $encodedPayload . '.' . $this->base64UrlEncode($signature);
    }

    public function validateAccessToken(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed access token.', 401);
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $expectedSignature = $this->base64UrlEncode(
            hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $this->jwtSecret, true)
        );

        if (!hash_equals($expectedSignature, $encodedSignature)) {
            throw new RuntimeException('Invalid access token signature.', 401);
        }

        $payloadJson = $this->base64UrlDecode($encodedPayload);
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid access token payload.', 401);
        }

        $now = time();
        if (($payload['nbf'] ?? 0) > $now) {
            throw new RuntimeException('Access token is not active yet.', 401);
        }

        if (($payload['exp'] ?? 0) < $now) {
            throw new RuntimeException('Access token has expired.', 401);
        }

        if (($payload['iss'] ?? '') !== $this->jwtIssuer) {
            throw new RuntimeException('Unexpected access token issuer.', 401);
        }

        return $payload;
    }

    public function generateRefreshToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function refreshTokenHash(string $refreshToken): string
    {
        return hash('sha256', $refreshToken);
    }

    public function refreshTokenExpiry(): string
    {
        return gmdate('Y-m-d H:i:s', time() + ($this->refreshTokenTtlDays * 86400));
    }

    public function accessTokenTtlMinutes(): int
    {
        return $this->accessTokenTtlMinutes;
    }

    private function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $input): string
    {
        $padding = strlen($input) % 4;
        if ($padding > 0) {
            $input .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($input, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Failed to decode token.', 401);
        }

        return $decoded;
    }
}
