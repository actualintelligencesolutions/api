<?php

declare(strict_types=1);

final class Response
{
    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    public static function success(array $data, int $status = 200): void
    {
        self::json(
            [
                'success' => true,
                'data' => $data,
            ],
            $status
        );
    }

    public static function error(string $message, int $status, array $errors = []): void
    {
        self::json(
            [
                'success' => false,
                'error' => [
                    'message' => $message,
                    'details' => $errors,
                ],
            ],
            $status
        );
    }
}
