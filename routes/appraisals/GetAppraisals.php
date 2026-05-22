<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/AppraisalHelpers.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Bad Request: Only GET method is allowed', 405);

    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];
    $loggedInRole = $userData['role'] ?? '';
    $loggedInRoleKey = strtolower(str_replace(' ', '_', trim((string)$loggedInRole)));
    $loggedInCompanyId = isset($userData['company_id']) ? (int)$userData['company_id'] : 0;

    $cycleId = isset($_GET['cycle_id']) ? (int)$_GET['cycle_id'] : 0;
    $supervisorId = isset($_GET['supervisor_id']) ? (int)$_GET['supervisor_id'] : 0;
    $staffUserId = isset($_GET['staff_user_id']) ? (int)$_GET['staff_user_id'] : 0;
    $department = apEsc($conn, $_GET['department'] ?? '');
    $staffType = apEsc($conn, $_GET['staff_type'] ?? '');
    $status = apEsc($conn, $_GET['status'] ?? '');
    $search = apEsc($conn, $_GET['search'] ?? '');
    $scope = strtolower(trim((string)($_GET['scope'] ?? '')));

    $page = isset($_GET['page']) ? max((int)$_GET['page'], 1) : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $limit = in_array($limit, [10,20,50,100], true) ? $limit : 10;
    $offset = ($page - 1) * $limit;

    $allowedSort = ['staff_fullname' => 'ap.staff_fullname', 'appraisal_summary' => 'ap.appraisal_summary', 'kpi_rating' => 'ap.kpi_rating', 'created_at' => 'ap.created_at', 'status' => 'ap.status'];
    $sortBy = $_GET['sortBy'] ?? 'created_at';
    $sortColumn = $allowedSort[$sortBy] ?? 'ap.created_at';
    $sortOrder = strtoupper($_GET['sortOrder'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

    $where = ['1=1'];
    $isOwnScope = in_array($scope, ['my', 'own', 'self'], true);
    $companyScope = resolveCompanyScope($userData);

    if ($loggedInRoleKey === 'super_admin' && $isOwnScope) {
        throw new Exception('Super administrators do not have personal appraisals.', 403);
    }

    if ($loggedInRoleKey === 'super_admin') {
        // Super administrators manage reporting only and are not appraisal subjects.
        if ($companyScope !== null) $where[] = "ap.company_id = " . (int) $companyScope;
    } elseif ($isOwnScope) {
        // Staff, supervisors and administrators can all be appraisal subjects.
        $where[] = "ap.staff_user_id = {$loggedInUserId}";
    } elseif ($loggedInRoleKey === 'staff') {
        $where[] = "ap.staff_user_id = {$loggedInUserId}";
    } elseif ($loggedInRoleKey === 'supervisor') {
        $where[] = "ap.supervisor_id = {$loggedInUserId}";
    } elseif ($loggedInRoleKey === 'admin') {
        $where[] = "ap.company_id = {$loggedInCompanyId}";
        $adminScope = trim((string) ($userData['staff_scope'] ?? 'All'));
        if (!$isOwnScope && in_array($adminScope, ['Local', 'Expatriate'], true)) {
            $safeAdminScope = apEsc($conn, $adminScope);
            $where[] = "(NOT EXISTS (
                SELECT 1
                FROM users subject_user
                INNER JOIN roles subject_role ON subject_role.id = subject_user.role_id
                WHERE subject_user.id = ap.staff_user_id
                  AND LOWER(REPLACE(TRIM(subject_role.name), ' ', '_')) = 'staff'
            ) OR ap.staff_type = '{$safeAdminScope}')";
        }
    }

    if ($cycleId > 0) $where[] = "ap.cycle_id = {$cycleId}";
    if ($supervisorId > 0 && in_array($loggedInRoleKey, ['super_admin','admin'], true)) $where[] = "ap.supervisor_id = {$supervisorId}";
    if ($staffUserId > 0 && in_array($loggedInRoleKey, ['super_admin','admin','supervisor'], true)) $where[] = "ap.staff_user_id = {$staffUserId}";
    if ($department !== '') $where[] = "ap.staff_department = '{$department}'";
    if ($staffType !== '') $where[] = "ap.staff_type = '{$staffType}'";
    if (in_array($status, ['Pending','Acknowledged'], true)) $where[] = "ap.status = '{$status}'";
    if ($search !== '') $where[] = "(ap.staff_fullname LIKE '%{$search}%' OR ap.staff_email LIKE '%{$search}%' OR ap.staff_department LIKE '%{$search}%' OR ap.staff_job_title LIKE '%{$search}%' OR ac.title LIKE '%{$search}%' OR ac.year LIKE '%{$search}%' OR sup.first_name LIKE '%{$search}%' OR sup.last_name LIKE '%{$search}%')";

    $whereSql = implode(' AND ', $where);
    $baseFrom = "
        FROM appraisals ap
        INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id
        INNER JOIN companies c ON c.id = ap.company_id
        LEFT JOIN users sup ON sup.id = ap.supervisor_id
        WHERE {$whereSql}
    ";

    $count = apFetchOne($conn, "SELECT COUNT(*) AS total {$baseFrom}");
    $total = (int)($count['total'] ?? 0);
    $totalPages = max((int)ceil($total / $limit), 1);
    if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $limit; }

    $rows = apFetchAll($conn, "
        SELECT
            ap.id, ap.company_id, ap.cycle_id, ap.staff_user_id, ap.supervisor_id,
            ap.staff_fullname, ap.staff_department, ap.staff_location, ap.staff_job_title,
            ap.staff_type, ap.staff_email, ap.kpi_rating, ap.appraisal_summary,
            ap.evaluation_statement, ap.status, ap.feedback, ap.edited_count, ap.update_count,
            ap.created_at, ap.updated_at,
            ac.year AS cycle_year, ac.title AS cycle_title,
            c.name AS company_name, c.code AS company_code,
            TRIM(CONCAT(COALESCE(sup.first_name,''), ' ', COALESCE(sup.last_name,''))) AS supervisor_name,
            sup.email AS supervisor_email
        {$baseFrom}
        ORDER BY {$sortColumn} {$sortOrder}, ap.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ");

    $stats = apFetchOne($conn, "
        SELECT
            COUNT(*) AS total_appraisals,
            COALESCE(SUM(CASE WHEN ap.status = 'Pending' THEN 1 ELSE 0 END), 0) AS pending_count,
            COALESCE(SUM(CASE WHEN ap.status = 'Acknowledged' THEN 1 ELSE 0 END), 0) AS acknowledged_count,
            ROUND(AVG(ap.appraisal_summary), 2) AS avg_score
        {$baseFrom}
    ") ?: [];

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Appraisals fetched successfully',
        'data' => $rows,
        'meta' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => $totalPages,
            'stats' => $stats,
            'sortBy' => $sortBy,
            'sortOrder' => $sortOrder,
        ],
    ]);

} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
