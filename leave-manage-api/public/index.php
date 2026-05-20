<?php

declare(strict_types=1);

$rootPath = dirname(__DIR__);

require_once $rootPath . '/config/env.php';
Env::load($rootPath . '/.env');

require_once $rootPath . '/config/db.php';
require_once $rootPath . '/src/Response.php';
require_once $rootPath . '/src/Request.php';
require_once $rootPath . '/src/Validator.php';
require_once $rootPath . '/src/TokenService.php';
require_once $rootPath . '/src/BaseService.php';
require_once $rootPath . '/src/AuthService.php';
require_once $rootPath . '/src/UserService.php';
require_once $rootPath . '/src/MasterDataService.php';
require_once $rootPath . '/src/ApproverGroupService.php';
require_once $rootPath . '/src/HolidayService.php';
require_once $rootPath . '/src/LeaveBalanceService.php';
require_once $rootPath . '/src/CompOffService.php';
require_once $rootPath . '/src/LeaveService.php';
require_once $rootPath . '/src/ApprovalService.php';
require_once $rootPath . '/src/DashboardService.php';
require_once $rootPath . '/src/ImportService.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
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
    $db = Database::connection();
    $tokenService = new TokenService();
    $authService = new AuthService($db, $tokenService);
    $userService = new UserService($db);
    $masterDataService = new MasterDataService($db);
    $approverGroupService = new ApproverGroupService($db);
    $holidayService = new HolidayService($db);
    $leaveBalanceService = new LeaveBalanceService($db);
    $compOffService = new CompOffService($db);
    $leaveService = new LeaveService($db, $leaveBalanceService, $compOffService);
    $approvalService = new ApprovalService($db, $leaveService, $leaveBalanceService, $compOffService);
    $dashboardService = new DashboardService($db, $leaveBalanceService, $leaveService);
    $importService = new ImportService($db);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = Request::normalizedPath($_SERVER['REQUEST_URI'] ?? '/');
    $jsonBody = Request::jsonBody();
    $input = Request::input($jsonBody);
    $authorizationHeader = Request::authorizationHeader();
    $currentSession = null;

    if ($method === 'GET' && $path === '/') {
        Response::success([
            'name' => 'Leave Manage PHP API',
            'status' => 'ok',
            'version' => 'v1',
        ]);
        exit;
    }

    $protectedRoutes = [
        '/api/v1/auth/me',
        '/api/v1/auth/change-pin',
        '/api/v1/dashboard',
        '/api/v1/me/profile',
        '/api/v1/me/leave-balances',
        '/api/v1/me/leave-requests',
        '/api/v1/me/holidays',
        '/api/v1/me/comp-off',
        '/api/v1/approvals/pending',
        '/api/v1/admin/users',
        '/api/v1/admin/departments',
        '/api/v1/admin/designations',
        '/api/v1/admin/leave-types',
        '/api/v1/admin/approver-groups',
        '/api/v1/admin/holidays',
        '/api/v1/admin/leave-requests',
        '/api/v1/admin/leave-balances',
        '/api/v1/admin/leave-balances/adjust',
        '/api/v1/admin/comp-off',
        '/api/v1/admin/bootstrap-import',
    ];

    if (
        str_starts_with($path, '/api/v1/me/')
        || str_starts_with($path, '/api/v1/admin/')
        || str_starts_with($path, '/api/v1/approvals/')
        || in_array($path, $protectedRoutes, true)
    ) {
        $currentSession = $authService->authenticateBearerToken($authorizationHeader);
    }

    if ($path === '/admin/bootstrap-import') {
        handleBootstrapImportPage($authService, $importService);
        exit;
    }

    if ($method === 'POST' && $path === '/api/v1/auth/login') {
        $result = $authService->login($input);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && $path === '/api/v1/auth/refresh') {
        $result = $authService->refresh(Request::extractRefreshToken($input));
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && $path === '/api/v1/auth/logout') {
        if ($authorizationHeader !== null) {
            try {
                $currentSession = $authService->authenticateBearerToken($authorizationHeader);
            } catch (Throwable) {
                $currentSession = null;
            }
        }

        $result = $authService->logout(Request::extractRefreshToken($input), $currentSession['user'] ?? null);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'GET' && $path === '/api/v1/auth/me') {
        $result = $authService->me($currentSession['user']);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && $path === '/api/v1/auth/change-pin') {
        $result = $authService->changePin($currentSession['user'], $input);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'GET' && $path === '/api/v1/dashboard') {
        $result = $dashboardService->summary($currentSession['user']);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'GET' && $path === '/api/v1/me/profile') {
        $result = $userService->myProfile($currentSession['user']);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'GET' && $path === '/api/v1/me/leave-balances') {
        $result = $leaveBalanceService->myBalances($currentSession['user'], $_GET);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'GET' && $path === '/api/v1/me/leave-requests') {
        $result = $leaveService->listForMe($currentSession['user'], $_GET);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && $path === '/api/v1/me/leave-requests') {
        $result = $leaveService->apply($currentSession['user'], $input);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/api/v1/me/leave-requests/(\d+)$#', $path, $matches)) {
        $result = $leaveService->getForMe($currentSession['user'], (int) $matches[1]);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && preg_match('#^/api/v1/me/leave-requests/(\d+)/cancel$#', $path, $matches)) {
        $result = $leaveService->cancel($currentSession['user'], (int) $matches[1]);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'GET' && $path === '/api/v1/me/holidays') {
        $result = $holidayService->listForSelf($currentSession['user'], $_GET);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'GET' && $path === '/api/v1/me/comp-off') {
        $result = $compOffService->myCredits($currentSession['user'], $_GET);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'GET' && $path === '/api/v1/approvals/pending') {
        $result = $approvalService->pending($currentSession['user'], $_GET);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/api/v1/approvals/(\d+)$#', $path, $matches)) {
        $result = $approvalService->detail($currentSession['user'], (int) $matches[1]);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && preg_match('#^/api/v1/approvals/(\d+)/approve$#', $path, $matches)) {
        $result = $approvalService->approve($currentSession['user'], (int) $matches[1], $input);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && preg_match('#^/api/v1/approvals/(\d+)/reject$#', $path, $matches)) {
        $result = $approvalService->reject($currentSession['user'], (int) $matches[1], $input);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'GET' && $path === '/api/v1/admin/users') {
        $result = $userService->list($currentSession['user'], $_GET);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && $path === '/api/v1/admin/users') {
        $result = $userService->create($currentSession['user'], $input);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if (preg_match('#^/api/v1/admin/users/(\d+)$#', $path, $matches)) {
        $userId = (int) $matches[1];
        if ($method === 'GET') {
            $result = $userService->get($currentSession['user'], $userId);
            Response::success($result['data'], $result['status']);
            exit;
        }
        if ($method === 'PUT') {
            $result = $userService->update($currentSession['user'], $userId, $input, false);
            Response::success($result['data'], $result['status']);
            exit;
        }
        if ($method === 'PATCH') {
            $result = $userService->update($currentSession['user'], $userId, $input, true);
            Response::success($result['data'], $result['status']);
            exit;
        }
    }

    foreach ([
        'departments' => 'departments',
        'designations' => 'designations',
        'leave-types' => 'leave-types',
    ] as $slug => $entity) {
        $base = '/api/v1/admin/' . $slug;
        if ($method === 'GET' && $path === $base) {
            $result = $masterDataService->listEntity($currentSession['user'], $entity, $_GET);
            Response::success($result['data'], $result['status']);
            exit;
        }
        if ($method === 'POST' && $path === $base) {
            $result = $masterDataService->createEntity($currentSession['user'], $entity, $input);
            Response::success($result['data'], $result['status']);
            exit;
        }
        if (preg_match('#^' . preg_quote($base, '#') . '/(\d+)$#', $path, $matches)) {
            $id = (int) $matches[1];
            if ($method === 'GET') {
                $result = $masterDataService->getEntity($currentSession['user'], $entity, $id);
                Response::success($result['data'], $result['status']);
                exit;
            }
            if ($method === 'PUT') {
                $result = $masterDataService->updateEntity($currentSession['user'], $entity, $id, $input, false);
                Response::success($result['data'], $result['status']);
                exit;
            }
            if ($method === 'PATCH') {
                $result = $masterDataService->updateEntity($currentSession['user'], $entity, $id, $input, true);
                Response::success($result['data'], $result['status']);
                exit;
            }
        }
    }

    if ($method === 'GET' && $path === '/api/v1/admin/approver-groups') {
        $result = $approverGroupService->list($currentSession['user'], $_GET);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && $path === '/api/v1/admin/approver-groups') {
        $result = $approverGroupService->create($currentSession['user'], $input);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if (preg_match('#^/api/v1/admin/approver-groups/(\d+)$#', $path, $matches)) {
        $groupId = (int) $matches[1];
        if ($method === 'GET') {
            $result = $approverGroupService->get($currentSession['user'], $groupId);
            Response::success($result['data'], $result['status']);
            exit;
        }
        if ($method === 'PUT') {
            $result = $approverGroupService->update($currentSession['user'], $groupId, $input, false);
            Response::success($result['data'], $result['status']);
            exit;
        }
        if ($method === 'PATCH') {
            $result = $approverGroupService->update($currentSession['user'], $groupId, $input, true);
            Response::success($result['data'], $result['status']);
            exit;
        }
    }

    if (preg_match('#^/api/v1/admin/approver-groups/(\d+)/members$#', $path, $matches)) {
        $groupId = (int) $matches[1];
        if ($method === 'GET') {
            $result = $approverGroupService->listMembers($currentSession['user'], $groupId);
            Response::success($result['data'], $result['status']);
            exit;
        }
        if ($method === 'POST') {
            $result = $approverGroupService->createMember($currentSession['user'], $groupId, $input);
            Response::success($result['data'], $result['status']);
            exit;
        }
    }

    if ($method === 'PATCH' && preg_match('#^/api/v1/admin/approver-groups/(\d+)/members/(\d+)$#', $path, $matches)) {
        $result = $approverGroupService->updateMember($currentSession['user'], (int) $matches[1], (int) $matches[2], $input);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'GET' && $path === '/api/v1/admin/holidays') {
        $result = $holidayService->list($currentSession['user'], $_GET);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && $path === '/api/v1/admin/holidays') {
        $result = $holidayService->create($currentSession['user'], $input);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if (preg_match('#^/api/v1/admin/holidays/(\d+)$#', $path, $matches)) {
        $holidayId = (int) $matches[1];
        if ($method === 'GET') {
            $result = $holidayService->get($currentSession['user'], $holidayId);
            Response::success($result['data'], $result['status']);
            exit;
        }
        if ($method === 'PUT') {
            $result = $holidayService->update($currentSession['user'], $holidayId, $input, false);
            Response::success($result['data'], $result['status']);
            exit;
        }
        if ($method === 'PATCH') {
            $result = $holidayService->update($currentSession['user'], $holidayId, $input, true);
            Response::success($result['data'], $result['status']);
            exit;
        }
    }

    if ($method === 'GET' && $path === '/api/v1/admin/leave-requests') {
        $result = $leaveService->listRequests($currentSession['user'], $_GET);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'GET' && $path === '/api/v1/admin/leave-balances') {
        $result = $leaveBalanceService->list($currentSession['user'], $_GET);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && $path === '/api/v1/admin/leave-balances/adjust') {
        $result = $leaveBalanceService->adjust($currentSession['user'], $input);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'GET' && $path === '/api/v1/admin/comp-off') {
        $result = $compOffService->listCredits($currentSession['user'], $_GET);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && $path === '/api/v1/admin/comp-off') {
        $result = $compOffService->create($currentSession['user'], $input);
        Response::success($result['data'], $result['status']);
        exit;
    }

    if ($method === 'POST' && $path === '/api/v1/admin/bootstrap-import') {
        $payload = resolveImportPayload($input, $importService);
        $result = $importService->importBootstrapPayload(
            $currentSession['user'],
            $payload['payload'],
            $payload['source_name']
        );
        Response::success($result['data'], $result['status']);
        exit;
    }

    if (preg_match('#^/api/v1/admin/comp-off/(\d+)$#', $path, $matches)) {
        $creditId = (int) $matches[1];
        if ($method === 'GET') {
            $result = $compOffService->get($currentSession['user'], $creditId);
            Response::success($result['data'], $result['status']);
            exit;
        }
        if ($method === 'PUT') {
            $result = $compOffService->update($currentSession['user'], $creditId, $input, false);
            Response::success($result['data'], $result['status']);
            exit;
        }
        if ($method === 'PATCH') {
            $result = $compOffService->update($currentSession['user'], $creditId, $input, true);
            Response::success($result['data'], $result['status']);
            exit;
        }
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

function resolveImportPayload(array $input, ImportService $importService): array
{
    if (isset($_FILES['import_file']) && is_array($_FILES['import_file'])) {
        return $importService->parseUploadedJsonFile($_FILES['import_file']);
    }

    if (isset($input['payload']) && is_array($input['payload'])) {
        return [
            'source_name' => 'request-payload',
            'payload' => $input['payload'],
        ];
    }

    if ($input !== []) {
        return [
            'source_name' => 'request-body',
            'payload' => $input,
        ];
    }

    throw new InvalidArgumentException('Import payload is required. Upload import_file or send payload JSON.', 422);
}

function handleBootstrapImportPage(AuthService $authService, ImportService $importService): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $errors = [];
    $result = null;
    $sessionUser = null;

    if (isset($_SESSION['leave_manage_super_admin_id'])) {
        $sessionUser = authServiceSessionUser((int) $_SESSION['leave_manage_super_admin_id']);
        if ($sessionUser === null || $sessionUser['role'] !== 'super_admin' || $sessionUser['status'] !== 'active') {
            unset($_SESSION['leave_manage_super_admin_id']);
            $sessionUser = null;
        }
    }

    if ($method === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action === 'logout') {
            unset($_SESSION['leave_manage_super_admin_id']);
            header('Location: ' . buildLocalPath('/admin/bootstrap-import'), true, 303);
            exit;
        }

        if ($action === 'login') {
            try {
                $sessionUser = loginBootstrapPageSuperAdmin($authService);
                $_SESSION['leave_manage_super_admin_id'] = $sessionUser['id'];
                header('Location: ' . buildLocalPath('/admin/bootstrap-import'), true, 303);
                exit;
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        if ($action === 'import') {
            if ($sessionUser === null) {
                $errors[] = 'Please log in as a super_admin first.';
            } else {
                try {
                    $payload = resolveBootstrapPageImportPayload($importService);
                    $import = $importService->importBootstrapPayload(
                        $sessionUser,
                        $payload['payload'],
                        $payload['source_name']
                    );
                    $result = $import['data'];
                } catch (Throwable $exception) {
                    $errors[] = $exception->getMessage();
                }
            }
        }
    }

    $html = renderBootstrapImportPage($sessionUser, $errors, $result);
    sendHtml($html);
}

function authServiceSessionUser(int $userId): ?array
{
    $stmt = Database::connection()->prepare(
        'SELECT u.*,
                d.name AS department_name,
                g.name AS designation_name,
                ag.name AS approver_group_name
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         LEFT JOIN designations g ON g.id = u.designation_id
         LEFT JOIN approver_groups ag ON ag.id = u.approver_group_id
         WHERE u.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

function loginBootstrapPageSuperAdmin(AuthService $authService): array
{
    $mobile = $_POST['mobile'] ?? null;
    $pin = $_POST['pin'] ?? null;

    if (bootstrapStaticLoginMatches($mobile, $pin)) {
        $configuredMobile = normalizeBootstrapStaticMobile((string) env('BOOTSTRAP_IMPORT_STATIC_SUPER_ADMIN_MOBILE', ''));
        if ($configuredMobile === null) {
            throw new RuntimeException('BOOTSTRAP_IMPORT_STATIC_SUPER_ADMIN_MOBILE is not configured correctly.', 500);
        }

        $user = findBootstrapSuperAdminByMobile($configuredMobile);
        if ($user === null) {
            throw new RuntimeException('Configured static bootstrap super admin was not found in the database.', 404);
        }

        if (($user['role'] ?? null) !== 'super_admin' || ($user['status'] ?? null) !== 'active') {
            throw new RuntimeException('Configured static bootstrap user must be an active super_admin.', 403);
        }

        return $user;
    }

    $login = $authService->login([
        'mobile' => $mobile,
        'pin' => $pin,
        'device_uuid' => 'php-bootstrap-import-page',
    ]);
    $user = $login['data']['user'];
    if (($user['role'] ?? null) !== 'super_admin') {
        throw new RuntimeException('Only super_admin users can access the bootstrap import page.', 403);
    }

    $sessionUser = authServiceSessionUser((int) $user['id']);
    if ($sessionUser === null) {
        throw new RuntimeException('Super admin user could not be loaded after login.', 500);
    }

    return $sessionUser;
}

function bootstrapStaticLoginMatches(mixed $mobile, mixed $pin): bool
{
    $configuredMobile = normalizeBootstrapStaticMobile((string) env('BOOTSTRAP_IMPORT_STATIC_SUPER_ADMIN_MOBILE', ''));
    $configuredPin = trim((string) env('BOOTSTRAP_IMPORT_STATIC_SUPER_ADMIN_PIN', ''));

    if ($configuredMobile === null || $configuredPin === '') {
        return false;
    }

    if (!is_string($mobile) && !is_int($mobile)) {
        return false;
    }
    if (!is_string($pin) && !is_int($pin)) {
        return false;
    }

    $submittedMobile = normalizeBootstrapStaticMobile((string) $mobile);
    $submittedPin = trim((string) $pin);

    if ($submittedMobile === null || $submittedPin === '') {
        return false;
    }

    return hash_equals($configuredMobile, $submittedMobile) && hash_equals($configuredPin, $submittedPin);
}

function normalizeBootstrapStaticMobile(string $mobile): ?string
{
    $digits = preg_replace('/\D+/', '', trim($mobile));
    if (!is_string($digits) || $digits === '') {
        return null;
    }

    return $digits;
}

function findBootstrapSuperAdminByMobile(string $mobile): ?array
{
    $stmt = Database::connection()->prepare(
        'SELECT u.*,
                d.name AS department_name,
                g.name AS designation_name,
                ag.name AS approver_group_name
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         LEFT JOIN designations g ON g.id = u.designation_id
         LEFT JOIN approver_groups ag ON ag.id = u.approver_group_id
         WHERE u.mobile = :mobile
         LIMIT 1'
    );
    $stmt->execute(['mobile' => $mobile]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

function sendHtml(string $html, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
}

function buildLocalPath(string $path): string
{
    $basePath = parse_url((string) env('APP_URL', ''), PHP_URL_PATH);
    $prefix = is_string($basePath) ? rtrim($basePath, '/') : '';
    return $prefix . $path;
}

function resolveBootstrapPageImportPayload(ImportService $importService): array
{
    $staffFile = $_FILES['staff_master_file'] ?? null;
    $holidayFile = $_FILES['holiday_calendar_file'] ?? null;
    $hasStaffFile = is_array($staffFile) && (($staffFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK);
    $hasHolidayFile = is_array($holidayFile) && (($holidayFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK);

    if ($hasStaffFile || $hasHolidayFile) {
        $payload = [];
        $sourceNames = [];

        if ($hasStaffFile) {
            $staffPayload = $importService->parseUploadedJsonFile($staffFile);
            $payload['staff_master'] = $staffPayload['payload'];
            $sourceNames[] = $staffPayload['source_name'];
        }

        if ($hasHolidayFile) {
            $holidayPayload = $importService->parseUploadedJsonFile($holidayFile);
            $payload['holiday_calendar'] = $holidayPayload['payload'];
            $sourceNames[] = $holidayPayload['source_name'];
        }

        return [
            'source_name' => implode(' + ', $sourceNames),
            'payload' => $payload,
        ];
    }

    throw new InvalidArgumentException('Upload staff_master.json and/or holiday_calendar.json to continue.', 422);
}

function renderBootstrapImportPage(?array $sessionUser, array $errors, ?array $result): string
{
    $isLoggedIn = $sessionUser !== null;
    $pageTitle = 'Leave Manage Bootstrap Import';
    $escapedTitle = htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8');
    $loginPanel = renderBootstrapLoginPanel($errors);
    $importPanel = renderBootstrapImportPanel($sessionUser, $errors, $result);

    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>' . $escapedTitle . '</title>
    <style>
        body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f4f6f8;color:#16202a}
        .page{max-width:920px;margin:0 auto;padding:32px 20px 60px}
        .card{background:#fff;border-radius:16px;padding:24px;box-shadow:0 12px 30px rgba(0,0,0,.08);margin-bottom:20px}
        h1,h2,h3{margin:0 0 12px}
        p{line-height:1.5}
        label{display:block;font-weight:600;margin:14px 0 6px}
        input[type="text"],input[type="password"],input[type="file"],textarea{width:100%;padding:12px 14px;border:1px solid #cfd7df;border-radius:10px;box-sizing:border-box}
        button{border:0;border-radius:10px;padding:12px 18px;font-weight:700;cursor:pointer;background:#0b6bcb;color:#fff}
        button.secondary{background:#5b6770}
        .row{display:flex;gap:12px;flex-wrap:wrap}
        .row > *{flex:1 1 240px}
        .error{background:#fff2f2;color:#8a1f1f;border:1px solid #f1b7b7;padding:12px 14px;border-radius:10px;margin:0 0 12px}
        .success{background:#eef9f0;color:#1f6b2b;border:1px solid #b7e0bd;padding:12px 14px;border-radius:10px;margin:0 0 12px}
        .muted{color:#5b6770}
        pre{background:#0f1720;color:#e6edf3;padding:16px;border-radius:12px;overflow:auto;font-size:13px}
        .topbar{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
        .badge{display:inline-block;padding:6px 10px;border-radius:999px;background:#e8f1fb;color:#0b4d92;font-weight:700;font-size:12px}
    </style>
</head>
<body>
    <div class="page">
        <div class="card">
            <div class="topbar">
                <div>
                    <h1>' . $escapedTitle . '</h1>
                    <p class="muted">Upload one bootstrap JSON file, or separately upload staff and holiday source JSON files for server-side normalization.</p>
                </div>
                ' . ($isLoggedIn ? '<span class="badge">Logged in as ' . htmlspecialchars((string) $sessionUser['full_name'], ENT_QUOTES, 'UTF-8') . '</span>' : '') . '
            </div>
        </div>
        ' . ($isLoggedIn ? $importPanel : $loginPanel) . '
        <div class="card">
            <h2>Expected JSON Shape</h2>
            <p class="muted">Every section is optional, but when present it must be an array of objects.</p>
            <pre>{
  "departments": [{"code":"HR","name":"Human Resources","status":"active"}],
  "designations": [{"code":"MGR","name":"Manager","status":"active"}],
  "approver_groups": [{
    "code":"BLR-ADMINS",
    "name":"Bangalore Admins",
    "description":"Primary Bangalore approvers",
    "status":"active",
    "members":[{"employee_code":"EMP100","member_role":"primary","status":"active"}]
  }],
  "users": [{
    "employee_code":"EMP100",
    "full_name":"Jane Doe",
    "mobile":"9876543210",
    "email":"jane@example.com",
    "role":"super_admin",
    "pin":"1234",
    "status":"active",
    "department_code":"HR",
    "designation_code":"MGR",
    "approver_group_code":"BLR-ADMINS",
    "joining_date":"2026-05-15"
  }],
  "leave_types": [{
    "code":"CL",
    "name":"Casual Leave",
    "yearly_quota":12,
    "carry_forward_allowed":0,
    "max_carry_forward":0,
    "requires_approval":1,
    "allow_comp_off":0,
    "status":"active"
  }],
  "holidays": [{"holiday_date":"2026-08-15","name":"Independence Day","holiday_type":"public","is_optional":0}],
  "leave_balances": [{"employee_code":"EMP100","leave_type_code":"CL","period_year":2026,"opening_balance":12,"credited_balance":0,"used_balance":0,"pending_balance":0}]
}</pre>
        </div>
    </div>
</body>
</html>';
}

function renderBootstrapLoginPanel(array $errors): string
{
    $errorHtml = renderErrorMessages($errors);
    return '<div class="card">
        <h2>Super Admin Login</h2>
        <p class="muted">Log in with the same mobile number and PIN you use for the API.</p>
        ' . $errorHtml . '
        <form method="post" action="">
            <input type="hidden" name="action" value="login">
            <label for="mobile">Mobile</label>
            <input id="mobile" name="mobile" type="text" inputmode="numeric" autocomplete="username">
            <label for="pin">PIN</label>
            <input id="pin" name="pin" type="password" inputmode="numeric" autocomplete="current-password">
            <div style="margin-top:18px"><button type="submit">Log In</button></div>
        </form>
    </div>';
}

function renderBootstrapImportPanel(?array $sessionUser, array $errors, ?array $result): string
{
    $errorHtml = renderErrorMessages($errors);
    $successHtml = '';
    if ($result !== null) {
        $successHtml = '<div class="success"><strong>' . htmlspecialchars((string) ($result['message'] ?? 'Import completed.'), ENT_QUOTES, 'UTF-8') . '</strong><br><small>Run ID: ' . htmlspecialchars((string) ($result['import_run_id'] ?? ''), ENT_QUOTES, 'UTF-8') . '</small></div><pre>' . htmlspecialchars((string) json_encode($result['summary'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') . '</pre>';
    }

    return '<div class="card">
        <div class="topbar">
            <div>
                <h2>Upload Bootstrap JSON</h2>
                <p class="muted">Signed in as ' . htmlspecialchars((string) ($sessionUser['full_name'] ?? 'Unknown user'), ENT_QUOTES, 'UTF-8') . '.</p>
            </div>
            <form method="post" action="">
                <input type="hidden" name="action" value="logout">
                <button class="secondary" type="submit">Log Out</button>
            </form>
        </div>
        ' . $errorHtml . '
        ' . $successHtml . '
        <form method="post" action="" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import">
            <div class="row">
                <div>
                    <label for="staff_master_file">Staff master JSON</label>
                    <input id="staff_master_file" name="staff_master_file" type="file" accept=".json,application/json">
                </div>
                <div>
                    <label for="holiday_calendar_file">Holiday calendar JSON</label>
                    <input id="holiday_calendar_file" name="holiday_calendar_file" type="file" accept=".json,application/json">
                </div>
            </div>
            <p class="muted">Upload one or both of these raw source files to let the API normalize them into users, approver groups, leave balances, and holidays.</p>
            <p class="muted">The import runs inside one database transaction. If any row is invalid, the whole import is rolled back.</p>
            <div style="margin-top:18px"><button type="submit">Upload And Import</button></div>
        </form>
    </div>';
}

function renderErrorMessages(array $errors): string
{
    if ($errors === []) {
        return '';
    }

    $html = '';
    foreach ($errors as $error) {
        $html .= '<div class="error">' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    return $html;
}
