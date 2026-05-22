<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

function jsonResponse($status, $message, $data = [], $meta = [], $code = 200)
{
    http_response_code($code);
    echo json_encode([
        'status'  => $status,
        'message' => $message,
        'data'    => $data,
        'meta'    => $meta,
    ]);
    exit;
}

function fetchOneRaw($conn, $sql)
{
    $result = $conn->query($sql);
    if (!$result) throw new Exception('Database error: ' . $conn->error, 500);
    return $result->fetch_assoc();
}

function fetchAllRaw($conn, $sql)
{
    $result = $conn->query($sql);
    if (!$result) throw new Exception('Database error: ' . $conn->error, 500);
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    return $rows;
}

function roleKeySql($alias)
{
    return "LOWER(REPLACE(TRIM({$alias}.name), ' ', '_'))";
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Bad Request: Only GET method is allowed', 405);
    }

    $userData = requireRoles(['super_admin', 'admin']);

    $loggedRole      = $userData['role'] ?? '';
    $loggedRoleKey   = strtolower(str_replace(' ', '_', trim((string) $loggedRole)));
    $loggedCompanyId = isset($userData['company_id']) ? (int) $userData['company_id'] : 0;

    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $cycleId = isset($_GET['cycle_id']) ? (int) $_GET['cycle_id'] : 0;

    if ($id <= 0) throw new Exception('Supervisor id is required.', 400);

    $companyScope = resolveCompanyScope($userData);
    $companySql = $companyScope !== null ? " AND u.company_id = " . (int) $companyScope : '';

    $supervisor = fetchOneRaw($conn, "
        SELECT
            u.id,
            u.company_id,
            c.code AS company_code,
            c.name AS company_name,
            u.role_id,
            r.name AS role_name,
            u.staff_id,
            u.first_name,
            u.last_name,
            u.fullname,
            u.username,
            u.email,
            u.staff_scope,
            u.department,
            u.job_title,
            u.staff_type,
            u.location,
            u.date_of_joining,
            u.unique_ref,
            u.is_active,
            u.onboarded_at,
            u.last_login_at,
            u.created_at,
            u.updated_at
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        LEFT JOIN companies c ON c.id = u.company_id
        WHERE u.id = {$id}
          AND " . appraiserRoleWhere('r') . "
          {$companySql}
        LIMIT 1
    ");

    if (!$supervisor) throw new Exception('Supervisor was not found or is outside your company scope.', 404);

    $scopeCompanyId = (int) $supervisor['company_id'];
    $cycle = null;

    if ($cycleId > 0) {
        $cycle = fetchOneRaw($conn, "
            SELECT id, company_id, title, year, is_active
            FROM appraisal_cycles
            WHERE id = {$cycleId}
              AND company_id = {$scopeCompanyId}
            LIMIT 1
        ");

        if (!$cycle) throw new Exception('Selected appraisal cycle was not found for this supervisor company.', 404);
    }

    $cycleFilter = $cycleId > 0 ? "AND sa.cycle_id = {$cycleId}" : '';
    $appraisalFilter = $cycleId > 0 ? "AND a.cycle_id = {$cycleId}" : '';
    $onboardFilter = $cycleId > 0 ? "AND onboard.cycle_id = {$cycleId}" : '';

    $subordinates = fetchAllRaw($conn, "
        SELECT
            staff.id,
            staff.staff_id,
            staff.first_name,
            staff.last_name,
            staff.fullname,
            staff.email,
            staff.department,
            staff.job_title,
            staff.staff_type,
            staff.location,
            staff.date_of_joining,
            staff.unique_ref,
            staff.is_active,
            CASE WHEN appr.staff_user_id IS NULL THEN 0 ELSE 1 END AS is_appraised,
            COALESCE(appr.status, '') AS appraisal_status,
            appr.appraisal_summary,
            appr.evaluation_statement
        FROM supervisor_assignments sa
        INNER JOIN users staff ON staff.id = sa.staff_id
        INNER JOIN roles sr ON sr.id = staff.role_id
        LEFT JOIN (
            SELECT
                staff_user_id,
                MAX(status) AS status,
                MAX(appraisal_summary) AS appraisal_summary,
                MAX(evaluation_statement) AS evaluation_statement
            FROM appraisals a
            WHERE supervisor_id = {$id}
              {$appraisalFilter}
            GROUP BY staff_user_id
        ) appr ON appr.staff_user_id = staff.id
        WHERE sa.supervisor_id = {$id}
          {$cycleFilter}
          AND " . appraiseeRoleWhere('sr') . "
        ORDER BY staff.first_name ASC, staff.last_name ASC
    ");

    $summary = fetchOneRaw($conn, "
        SELECT
            COUNT(sa.id) AS total_subordinates,
            COALESCE(SUM(CASE WHEN appr.staff_user_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS appraised_count,
            CASE
                WHEN COUNT(sa.id) = 0 THEN 0
                ELSE ROUND((COALESCE(SUM(CASE WHEN appr.staff_user_id IS NOT NULL THEN 1 ELSE 0 END), 0) / COUNT(sa.id)) * 100, 1)
            END AS progress_percent,
            CASE WHEN onboard.id IS NULL THEN 0 ELSE 1 END AS is_onboarded,
            onboard.onboarded_at AS cycle_onboarded_at
        FROM supervisor_assignments sa
        LEFT JOIN (
            SELECT staff_user_id
            FROM appraisals a
            WHERE supervisor_id = {$id}
              {$appraisalFilter}
            GROUP BY staff_user_id
        ) appr ON appr.staff_user_id = sa.staff_id
        LEFT JOIN supervisor_onboarding onboard
            ON onboard.supervisor_id = {$id}
            {$onboardFilter}
        WHERE sa.supervisor_id = {$id}
          {$cycleFilter}
    ") ?: [];

    $supervisor = array_merge($supervisor, [
        'total_subordinates' => (int) ($summary['total_subordinates'] ?? 0),
        'appraised_count'    => (int) ($summary['appraised_count'] ?? 0),
        'progress_percent'   => (float) ($summary['progress_percent'] ?? 0),
        'is_onboarded'       => (int) ($summary['is_onboarded'] ?? 0),
        'cycle_onboarded_at' => $summary['cycle_onboarded_at'] ?? null,
    ]);

    $meta = [
        'supervisor' => $supervisor,
        'cycle'      => $cycle ? [
            'id' => (int) $cycle['id'],
            'title' => $cycle['title'],
            'year' => $cycle['year'],
            'is_active' => (int) $cycle['is_active'],
        ] : null,
    ];

    jsonResponse('Success', 'Supervisor fetched successfully.', [
        'supervisor' => $supervisor,
        'subordinates' => $subordinates,
    ], $meta);
} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    jsonResponse('Failed', $e->getMessage(), [], [], $code);
}
