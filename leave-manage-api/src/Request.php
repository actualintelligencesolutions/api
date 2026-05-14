<?php

declare(strict_types=1);

final class Request
{
    public static function jsonBody(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (!is_string($contentType) || stripos($contentType, 'application/json') === false) {
            return [];
        }

        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || trim($rawBody) === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Request body must be valid JSON.', 400);
        }

        return $decoded;
    }

    public static function input(array $jsonBody): array
    {
        if ($jsonBody !== []) {
            return $jsonBody;
        }

        if ($_POST !== []) {
            return $_POST;
        }

        return [];
    }

    public static function authorizationHeader(): ?string
    {
        $headers = [
            $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            $_SERVER['Authorization'] ?? null,
        ];

        foreach ($headers as $header) {
            if (is_string($header) && trim($header) !== '') {
                return trim($header);
            }
        }

        return null;
    }

    public static function normalizedPath(string $requestUri): string
    {
        $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
        $basePath = parse_url((string) env('APP_URL', ''), PHP_URL_PATH);

        if (is_string($basePath)) {
            $basePath = rtrim($basePath, '/');

            if ($basePath !== '' && str_starts_with($path, $basePath)) {
                $path = substr($path, strlen($basePath)) ?: '/';
            }
        }

        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    public static function queryParam(string $name, bool $required = false): ?string
    {
        $value = $_GET[$name] ?? null;
        if ($value === null) {
            if ($required) {
                throw new InvalidArgumentException($name . ' is required.', 422);
            }

            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException($name . ' must be a string.', 422);
        }

        $normalized = trim($value);
        if ($normalized === '' && $required) {
            throw new InvalidArgumentException($name . ' is required.', 422);
        }

        return $normalized === '' ? null : $normalized;
    }

    public static function integerQueryParam(string $name, int $default): int
    {
        $value = self::queryParam($name, false);
        if ($value === null) {
            return $default;
        }

        if (!preg_match('/^-?\d+$/', $value)) {
            throw new InvalidArgumentException($name . ' must be an integer.', 422);
        }

        return (int) $value;
    }

    public static function extractRefreshToken(array $input): ?string
    {
        if (isset($input['refresh_token']) && is_string($input['refresh_token'])) {
            return trim($input['refresh_token']);
        }

        return null;
    }
}
