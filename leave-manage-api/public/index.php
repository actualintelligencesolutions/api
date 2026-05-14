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
    ];

    if (
        str_starts_with($path, '/api/v1/me/')
        || str_starts_with($path, '/api/v1/admin/')
        || str_starts_with($path, '/api/v1/approvals/')
        || in_array($path, $protectedRoutes, true)
    ) {
        $currentSession = $authService->authenticateBearerToken($authorizationHeader);
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
