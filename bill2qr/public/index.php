<?php

declare(strict_types=1);

$rootPath = dirname(__DIR__);

require_once $rootPath . '/config/env.php';
Env::load($rootPath . '/.env');

require_once $rootPath . '/config/db.php';
require_once $rootPath . '/src/Response.php';
require_once $rootPath . '/src/TokenService.php';
require_once $rootPath . '/src/AuthService.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_exception_handler(static function (Throwable $exception): void {
    $statusCode = $exception->getCode();
    if (!is_int($statusCode) || $statusCode < 100 || $statusCode > 599) {
        $statusCode = 500;
    }

    Response::error($exception->getMessage(), $statusCode);
});

try {
    $authService = new AuthService(Database::connection(), new TokenService());

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = normalizeRequestPath($requestUri);
    $body = getJsonInput();
    $authorizationHeader = getAuthorizationHeader();

    if ($method === 'GET' && $path === '/') {
        Response::success([
            'name' => 'Bill2QR Auth API',
            'status' => 'ok',
        ]);
        exit;
    }

    if ($method === 'POST' && $path === '/auth/register') {
        $result = $authService->register($body);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && $path === '/device/check-eligibility') {
        $result = $authService->checkEligibility($body);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && $path === '/device/check-upi-association') {
        $result = $authService->checkUpiAssociation($body);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && $path === '/device/verify-owner-for-claim') {
        $result = $authService->verifyOwnerForClaim($body);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && $path === '/device/register-user-device') {
        $result = $authService->registerUserDevice($body);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && $path === '/auth/login') {
        $result = $authService->login($body);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && $path === '/device/claim-user') {
        $currentSession = $authService->authenticateBearerToken($authorizationHeader);
        $result = $authService->claimUserDevice($body, $currentSession['device']);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && $path === '/auth/reset-pin') {
        $result = $authService->resetPin($body);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && $path === '/auth/update-upi') {
        $result = $authService->updateUpi($body);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && $path === '/auth/refresh') {
        $refreshToken = extractRefreshToken($body);
        $result = $authService->refresh($refreshToken);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && $path === '/auth/logout') {
        $currentSession = null;

        if ($authorizationHeader !== null) {
            try {
                $currentSession = $authService->authenticateBearerToken($authorizationHeader);
            } catch (Throwable) {
                $currentSession = null;
            }
        }

        $refreshToken = extractRefreshToken($body);
        $result = $authService->logout($refreshToken, $currentSession['device'] ?? null);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'GET' && $path === '/auth/me') {
        $currentSession = $authService->authenticateBearerToken($authorizationHeader);
        $result = $authService->me($currentSession['payload']);
        Response::success($result['data'], $result['status']);
        exit;
    }

    Response::error('Route not found.', 404);
} catch (Throwable $exception) {
    Response::error(
        $exception->getMessage(),
        is_int($exception->getCode()) && $exception->getCode() >= 100 && $exception->getCode() <= 599
            ? $exception->getCode()
            : 500
    );
}

function getJsonInput(): array
{
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

function getAuthorizationHeader(): ?string
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

function normalizeRequestPath(string $requestUri): string
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

function extractRefreshToken(array $body): ?string
{
    if (isset($body['refresh_token']) && is_string($body['refresh_token'])) {
        return trim($body['refresh_token']);
    }

    return null;
}
