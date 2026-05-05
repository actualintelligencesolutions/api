<?php

declare(strict_types=1);

$rootPath = dirname(__DIR__);

require_once $rootPath . '/config/env.php';
Env::load($rootPath . '/.env');

require_once $rootPath . '/config/db.php';
require_once $rootPath . '/src/Response.php';
require_once $rootPath . '/src/TokenService.php';
require_once $rootPath . '/src/AuthService.php';
require_once $rootPath . '/src/CampaignService.php';
require_once $rootPath . '/src/AccountDeletionService.php';

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
    $campaignService = new CampaignService(Database::connection());
    $accountDeletionService = new AccountDeletionService(
        Database::connection(),
        max(1, (int) env('ACCOUNT_DELETION_AUDIT_RETENTION_DAYS', '90'))
    );

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = normalizeRequestPath($requestUri);
    $body = getJsonInput();
    $requestInput = getRequestInput($body);
    $authorizationHeader = getAuthorizationHeader();

    if ($method === 'GET' && $path === '/') {
        Response::success([
            'name' => 'Bill2QR Auth API',
            'status' => 'ok',
        ]);
        exit;
    }

    if ($method === 'GET' && $path === '/account-deletion') {
        $result = $accountDeletionService->renderPublicPage();
        sendHtml($result['html'], $result['status'], [], accountDeletionContentSecurityPolicy());
        exit;
    }

    if ($method === 'POST' && $path === '/account-deletion/requests') {
        try {
            $result = $accountDeletionService->createRequest($requestInput);
        } catch (InvalidArgumentException $exception) {
            if (clientPrefersJson()) {
                Response::error($exception->getMessage(), resolveExceptionStatus($exception));
                exit;
            }

            $page = $accountDeletionService->renderPublicPage(
                $requestInput,
                [$exception->getMessage()]
            );
            sendHtml($page['html'], resolveExceptionStatus($exception), [], accountDeletionContentSecurityPolicy());
            exit;
        }

        if (clientPrefersJson()) {
            Response::success($result['data'], $result['status']);
            exit;
        }

        $page = $accountDeletionService->renderPublicPage(
            $requestInput,
            [],
            $result['data']
        );
        sendHtml($page['html'], $page['status'], [], accountDeletionContentSecurityPolicy());
        exit;
    }

    if ($method === 'GET' && $path === '/account-deletion/admin') {
        if (!$accountDeletionService->isAdminAuthorized($authorizationHeader)) {
            sendAdminUnauthorized(clientPrefersJson());
            exit;
        }

        $result = $accountDeletionService->renderAdminPage(getQueryParam('message', false));
        sendHtml($result['html'], $result['status'], [], accountDeletionContentSecurityPolicy());
        exit;
    }

    if ($method === 'POST' && $path === '/account-deletion/admin/review') {
        if (!$accountDeletionService->isAdminAuthorized($authorizationHeader)) {
            sendAdminUnauthorized(clientPrefersJson());
            exit;
        }

        try {
            $result = $accountDeletionService->reviewRequest($requestInput);
        } catch (Throwable $exception) {
            if (clientPrefersJson()) {
                Response::error($exception->getMessage(), resolveExceptionStatus($exception));
                exit;
            }

            $redirectUrl = buildPathWithQuery('/account-deletion/admin', [
                'message' => $exception->getMessage(),
            ]);
            header('Location: ' . $redirectUrl, true, 303);
            exit;
        }

        if (clientPrefersJson()) {
            Response::success($result['data'], $result['status']);
            exit;
        }

        $redirectUrl = buildPathWithQuery('/account-deletion/admin', [
            'message' => $result['data']['message'] ?? 'Review action completed.',
        ]);
        header('Location: ' . $redirectUrl, true, 303);
        exit;
    }

    if ($method === 'POST' && $path === '/campaigns/resolve') {
        $result = $campaignService->resolveActiveCampaign($body);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && $path === '/campaigns/events') {
        $result = $campaignService->trackEvent($body);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'GET' && $path === '/campaigns/html') {
        $result = $campaignService->renderHostedHtml(
            getQueryParam('campaign_key'),
            getQueryParam('device_uuid')
        );
        sendHtml($result['html'], $result['status']);
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

function getRequestInput(array $jsonBody): array
{
    if ($jsonBody !== []) {
        return $jsonBody;
    }

    if ($_POST !== []) {
        return $_POST;
    }

    return [];
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

function getQueryParam(string $name, bool $required = true): ?string
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

function sendHtml(string $html, int $status = 200, array $headers = [], ?string $contentSecurityPolicy = null): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Security-Policy: ' . ($contentSecurityPolicy ?? defaultHtmlContentSecurityPolicy()));
    foreach ($headers as $headerLine) {
        header($headerLine);
    }

    echo $html;
}

function clientPrefersJson(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (is_string($accept) && stripos($accept, 'application/json') !== false) {
        return true;
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    return is_string($contentType) && stripos($contentType, 'application/json') !== false;
}

function resolveExceptionStatus(Throwable $exception): int
{
    $statusCode = $exception->getCode();
    if (!is_int($statusCode) || $statusCode < 100 || $statusCode > 599) {
        return 500;
    }

    return $statusCode;
}

function sendAdminUnauthorized(bool $json): void
{
    $header = 'WWW-Authenticate: Basic realm="Bill2QR Account Deletion Admin"';

    if ($json) {
        header($header);
        Response::error('Admin authorization is required.', 401);
        return;
    }

    sendHtml(
        '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Unauthorized</title></head><body><h1>401 Unauthorized</h1><p>Admin authorization is required.</p></body></html>',
        401,
        [$header]
    );
}

function buildPathWithQuery(string $path, array $query): string
{
    $basePath = parse_url((string) env('APP_URL', ''), PHP_URL_PATH);
    $prefix = is_string($basePath) ? rtrim($basePath, '/') : '';
    $queryString = http_build_query($query);

    return $prefix . $path . ($queryString !== '' ? '?' . $queryString : '');
}

function defaultHtmlContentSecurityPolicy(): string
{
    return "default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; font-src https: data:; script-src 'none'; connect-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";
}

function accountDeletionContentSecurityPolicy(): string
{
    return "default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; font-src https: data:; script-src 'none'; connect-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'";
}
