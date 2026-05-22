<?php

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

/*
|--------------------------------------------------------------------------
| Central API response/error helpers
|--------------------------------------------------------------------------
| Router-level authentication failures must preserve their actual HTTP code.
| Never expose fatal file paths or exception messages in production.
*/
function apiIsProduction(): bool
{
    return strtolower((string)($_ENV['APP_ENV'] ?? 'development')) === 'production';
}

function apiExceptionStatus(Throwable $exception): int
{
    $code = (int)$exception->getCode();
    return ($code >= 400 && $code <= 599) ? $code : 500;
}

function apiSafeErrorMessage(Throwable $exception, int $status): string
{
    if ($status >= 500 && apiIsProduction()) {
        return 'Internal server error.';
    }

    return $exception->getMessage() ?: 'Request failed.';
}

set_exception_handler(function (Throwable $exception): void {
    $status = apiExceptionStatus($exception);

    error_log(sprintf(
        '[Lambert API] %s in %s:%d',
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode([
        'status'  => 'Failed',
        'message' => apiSafeErrorMessage($exception, $status),
    ]);

    exit;
});

register_shutdown_function(function (): void {
    $error = error_get_last();

    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    error_log(sprintf(
        '[Lambert API Fatal] %s in %s:%d',
        $error['message'],
        $error['file'],
        $error['line']
    ));

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode([
        'status'  => 'Failed',
        'message' => apiIsProduction()
            ? 'Internal server error.'
            : 'Fatal error: ' . $error['message'],
    ]);
});

ob_start();

/*
|--------------------------------------------------------------------------
| CORS configuration
|--------------------------------------------------------------------------
| Modes:
| - open:       temporarily allows any browser origin with credentials.
|               Never use this in production.
| - restricted: allows only origins listed in FRONTEND_ORIGIN.
|               Use this for production.
| - disabled:   returns no CORS access headers.
|               Your separated app/api domains will not work in a browser.
*/
$corsMode = strtolower(trim((string) ($_ENV['CORS_MODE'] ?? 'restricted')));

$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) ($_ENV['FRONTEND_ORIGIN'] ?? ''))
)));

$requestOrigin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
$originIsAllowed = false;

if ($requestOrigin !== '' && strtolower($requestOrigin) !== 'null') {
    if ($corsMode === 'open') {
        /*
         * Temporary debugging only:
         * With credentialed cookies we cannot send "*", so we reflect
         * the requesting origin instead.
         */
        $originIsAllowed = true;
    } elseif ($corsMode === 'restricted') {
        $originIsAllowed = in_array($requestOrigin, $allowedOrigins, true);
    }
}

if ($originIsAllowed) {
    header("Access-Control-Allow-Origin: {$requestOrigin}");
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
}

header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    /*
     * If the origin is blocked or CORS is disabled, do not return
     * a successful cross-origin preflight response.
     */
    if ($requestOrigin !== '' && !$originIsAllowed) {
        http_response_code(403);
        echo json_encode([
            'status'  => 'Failed',
            'message' => 'Cross-origin request is not permitted.',
        ]);
        ob_end_flush();
        exit;
    }

    http_response_code(204);
    ob_end_flush();
    exit;
}

require_once __DIR__ . '/includes/connection.php';
require_once __DIR__ . '/includes/AuthSecurity.php';

/*
|--------------------------------------------------------------------------
| Router
|--------------------------------------------------------------------------
*/
$requestUri = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$basePath = '/appraisal-server/api';
$relativePath = strpos($requestUri, $basePath) === 0
    ? substr($requestUri, strlen($basePath))
    : $requestUri;

if ($relativePath === '') {
    $relativePath = '/';
}

$routes = [
    '/' => function () {
        echo json_encode(["message" => "Welcome to the Appraisal System API 🎯"]);
    },

    // Auth
    '/auth/login'           => 'routes/auth/login.php',
    '/auth/session'         => 'routes/auth/Session.php',
    '/auth/logout'          => 'routes/auth/Logout.php',
    '/auth/change-password' => 'routes/auth/ChangePassword.php',

    // Users
    '/users/list'           => 'routes/users/GetUsers.php',
    '/users/search'           => 'routes/users/SearchUsers.php',
    '/users/single'         => 'routes/users/getSingleUser.php',
    '/users/create'         => 'routes/users/CreateUsers.php',
    '/users/update'         => 'routes/users/UpdateUser.php',
    '/users/update-profile' => 'routes/users/UpdateProfile.php',
    '/users/my-profile'     => 'routes/users/GetMyProfile.php',
    '/users/reset-password' => 'routes/users/ResetPassword.php',
    '/users/delete'         => 'routes/users/deleteUsers.php',

    // Companies (super_admin only)
    '/companies/list'   => 'routes/companies/GetCompanies.php',
    '/companies/search'   => 'routes/companies/SearchCompanies.php',
    '/companies/single'   => 'routes/companies/GetSingleCompany.php',
    '/companies/create' => 'routes/companies/CreateCompany.php',
    '/companies/update' => 'routes/companies/UpdateCompany.php',
    '/companies/delete' => 'routes/companies/DeleteCompanies.php',

    // Appraisal Cycles
    '/cycles/list'   => 'routes/cycles/GetCycles.php',
    '/cycles/single' => 'routes/cycles/GetSingleCycle.php',
    '/cycles/search' => 'routes/cycles/SearchCycles.php',
    '/cycles/create' => 'routes/cycles/CreateCycle.php',
    '/cycles/update' => 'routes/cycles/UpdateCycle.php',
    '/cycles/delete' => 'routes/cycles/DeleteCycles.php',
    '/cycles/copy'   => 'routes/cycles/CopyCycle.php',

    // Departments
    '/departments/list'   => 'routes/departments/GetDepartments.php',
    '/departments/search' => 'routes/departments/SearchDepartments.php',
    '/departments/single' => 'routes/departments/GetSingleDepartment.php',
    '/departments/create' => 'routes/departments/CreateDepartment.php',
    '/departments/update' => 'routes/departments/UpdateDepartment.php',
    '/departments/delete' => 'routes/departments/DeleteDepartments.php',


    // Bulk question routes
    '/general-questions/bulk-create' => 'routes/general-questions/BulkCreateGeneralQuestions.php',
    '/general-questions/bulk-update' => 'routes/general-questions/BulkUpdateGeneralQuestions.php',
    '/kpi-questions/bulk-create'     => 'routes/kpi-questions/BulkCreateKpiQuestions.php',
    '/kpi-questions/bulk-update'     => 'routes/kpi-questions/BulkUpdateKpiQuestions.php',


    // Sections
    '/sections/list'   => 'routes/sections/GetSections.php',
    '/sections/search' => 'routes/sections/SearchSections.php',
    '/sections/single' => 'routes/sections/GetSingleSection.php',
    '/sections/create' => 'routes/sections/CreateSection.php',
    '/sections/update' => 'routes/sections/UpdateSection.php',
    '/sections/delete' => 'routes/sections/DeleteSections.php',

    // KPI Questions
    '/kpi-questions/list'   => 'routes/kpi-questions/GetKpiQuestions.php',
    '/kpi-questions/search' => 'routes/kpi-questions/SearchKpiQuestions.php',
    '/kpi-questions/single' => 'routes/kpi-questions/GetSingleKpiQuestion.php',
    '/kpi-questions/create' => 'routes/kpi-questions/CreateKpiQuestion.php',
    '/kpi-questions/update' => 'routes/kpi-questions/UpdateKpiQuestion.php',
    '/kpi-questions/delete' => 'routes/kpi-questions/DeleteKpiQuestions.php',


    // KPI assignments
    '/kpi-assignments/get' => 'routes/kpi-assignments/GetKpiAssignments.php',
    '/kpi-assignments/set' => 'routes/kpi-assignments/SetKpiAssignments.php',


    // General Questions
    '/general-questions/list'   => 'routes/general-questions/GetGeneralQuestions.php',
    '/general-questions/search' => 'routes/general-questions/SearchGeneralQuestions.php',
    '/general-questions/single' => 'routes/general-questions/GetSingleGeneralQuestion.php',
    '/general-questions/create' => 'routes/general-questions/CreateGeneralQuestion.php',
    '/general-questions/update' => 'routes/general-questions/UpdateGeneralQuestion.php',
    '/general-questions/delete' => 'routes/general-questions/DeleteGeneralQuestions.php',

    // Supervisor Management
    '/supervisors/list'         => 'routes/supervisors/GetSupervisors.php',
    '/supervisors/single'       => 'routes/supervisors/GetSingleSupervisor.php',
    '/supervisors/search'       => 'routes/supervisors/SearchSupervisors.php',
    '/supervisors/onboard'      => 'routes/supervisors/Onboard.php',
    '/supervisors/subordinates' => 'routes/supervisors/GetSubordinates.php',
    '/supervisors/assign'       => 'routes/supervisors/AssignSubordinates.php',
    '/supervisors/unassign'     => 'routes/supervisors/UnassignSubordinates.php',
    '/supervisors/assignment-staff' => 'routes/supervisors/GetAssignmentStaff.php',

    // Appraisals
    '/appraisals/list'            => 'routes/appraisals/GetAppraisals.php',
    '/appraisals/single'          => 'routes/appraisals/GetSingleAppraisal.php',
    '/appraisals/candidates'      => 'routes/appraisals/GetAppraisalCandidates.php',
    '/appraisals/form-data'       => 'routes/appraisals/GetAppraisalFormData.php',
    '/appraisals/create'          => 'routes/appraisals/CreateAppraisal.php',
    '/appraisals/update'          => 'routes/appraisals/UpdateAppraisal.php',
    '/appraisals/submit-feedback' => 'routes/appraisals/SubmitFeedback.php',

    // Exports
    '/exports/appraisals/excel' => 'routes/exports/AppraisalExcelExport.php',

    // Manual Email
    '/mail/recipients' => 'routes/mail/GetMailRecipients.php',
    '/mail/send' => 'routes/mail/SendManualEmail.php',

    // Legacy endpoint - optional compatibility route
    '/mail/pending-acknowledgements' => 'routes/mail/GetPendingAcknowledgementStaff.php',

    // Audit
    '/audit-log/list' => 'routes/audit/GetAuditLog.php',

    // Notifications
    '/notifications/list' => 'routes/notifications/ListNotifications.php',
    '/notifications/read' => 'routes/notifications/MarkNotificationsRead.php',

    // Search
    '/search/global' => 'routes/search/GlobalSearch.php',

    // Dashboard
    '/dashboard' => 'routes/dashboard/GetDashboard.php',
];

if (!array_key_exists($relativePath, $routes)) {
    http_response_code(404);
    echo json_encode([
        'status'  => 'Failed',
        'message' => 'Route not found.',
    ]);
    ob_end_flush();
    exit;
}

/*
|--------------------------------------------------------------------------
| Central security gates
|--------------------------------------------------------------------------
| /auth/session manages its own optional authentication check so that an
| unauthenticated login-page session probe returns 401 rather than becoming a
| router-level failure. /auth/change-password must remain reachable by a
| logged-in staff/supervisor whose password change is required.
*/
$publicRoutes = [
    '/',
    '/auth/login',
    '/companies/list',
];

$routeManagedAuth = [
    '/auth/session',
    '/auth/logout',
    '/auth/change-password',
];

$passwordGateExempt = [
    '/',
    '/auth/login',
    '/auth/session',
    '/auth/logout',
    '/auth/change-password',
    '/companies/list',
];

$csrfExemptRoutes = [
    '/',
    '/auth/login',
    '/auth/session',
    '/companies/list',
];

/*
 * Apply CSRF protection centrally to every non-exempt mutation.
 * GET requests pass through the helper unchanged.
 */
if (!in_array($relativePath, $csrfExemptRoutes, true)) {
    requireCsrfForMutation();
}

if (
    !in_array($relativePath, $publicRoutes, true)
    && !in_array($relativePath, $routeManagedAuth, true)
) {
    require_once __DIR__ . '/includes/authMiddleware.php';

    $guardUser = authenticateUser();
    $guardRole = authRoleKey($guardUser['role'] ?? '');

    if (
        in_array($guardRole, ['admin', 'supervisor', 'staff'], true)
        && (int)($guardUser['must_change_password'] ?? 0) === 1
        && !in_array($relativePath, $passwordGateExempt, true)
    ) {
        http_response_code(428);
        echo json_encode([
            'status'  => 'Failed',
            'code'    => 'PASSWORD_CHANGE_REQUIRED',
            'message' => 'Password update required before continuing.',
        ]);
        ob_end_flush();
        exit;
    }
}

$route = $routes[$relativePath];

if (is_callable($route)) {
    $route();
} else {
    require __DIR__ . '/' . $route;
}

ob_end_flush();
exit;
