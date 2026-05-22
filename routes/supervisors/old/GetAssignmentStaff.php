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

function esc($conn, $value)
{
    return $conn->real_escape_string(trim((string) $value));
}

function fetchOneRaw($conn, $sql)
{
    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    return $result->fetch_assoc();
}

function fetchAllRaw($conn, $sql)
{
    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function roleKeySql($alias)
{
    return "LOWER(REPLACE(TRIM({$alias}.name), ' ', '_'))";
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 405);
    }

    $userData = requireRoles(['super_admin', 'admin']);

    $loggedRole      = $userData['role'] ?? '';
    $loggedRoleKey   = strtolower(str_replace(' ', '_', trim((string) $loggedRole)));
    $loggedCompanyId = isset($userData['company_id']) ? (int) $userData['company_id'] : 0;

    $cycleId      = isset($_GET['cycle_id']) ? (int) $_GET['cycle_id'] : 0;
    $supervisorId = isset($_GET['supervisor_id']) ? (int) $_GET['supervisor_id'] : 0;

    $search           = esc($conn, $_GET['search'] ?? '');
    $department       = esc($conn, $_GET['department'] ?? '');
    $staffType        = esc($conn, $_GET['staff_type'] ?? '');
    $assignmentStatus = esc($conn, $_GET['assignment_status'] ?? 'all');

    $page  = isset($_GET['page']) ? max((int) $_GET['page'], 1) : 1;
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
    $limit = in_array($limit, [10, 20, 50, 100], true) ? $limit : 10;
    $offset = ($page - 1) * $limit;

    $allowedSort = [
        'first_name' => 's.first_name',
        'last_name'  => 's.last_name',
        'department' => 's.department',
        'staff_type' => 's.staff_type',
        'job_title'  => 's.job_title',
        'email'      => 's.email',
    ];

    $sortBy = $_GET['sortBy'] ?? 'first_name';
    $sortColumn = $allowedSort[$sortBy] ?? 's.first_name';

    $sortOrder = strtoupper($_GET['sortOrder'] ?? 'ASC');
    $sortOrder = $sortOrder === 'DESC' ? 'DESC' : 'ASC';

    $emptyMeta = [
        'total'                   => 0,
        'page'                    => $page,
        'limit'                   => $limit,
        'total_pages'             => 1,
        'assigned_count'          => 0,
        'unassigned_count'        => 0,
        'assigned_to_other_count' => 0,
        'appraised_count'         => 0,
        'pending_count'           => 0,
        'progress_percent'        => 0,
        'cycle'                   => null,
        'supervisor'              => null,
    ];

    if ($cycleId <= 0 || $supervisorId <= 0) {
        jsonResponse('Success', 'Select a cycle and supervisor to load staff assignments.', [], $emptyMeta);
    }

    $cycleCompanySql = $loggedRoleKey !== 'super_admin'
        ? " AND company_id = {$loggedCompanyId}"
        : "";

    $cycle = fetchOneRaw($conn, "
        SELECT id, company_id, title, year, is_active
        FROM appraisal_cycles
        WHERE id = {$cycleId}
        {$cycleCompanySql}
        LIMIT 1
    ");

    if (!$cycle) {
        throw new Exception("Selected appraisal cycle was not found or is outside your company scope.", 404);
    }

    $scopeCompanyId = (int) $cycle['company_id'];

    $supervisor = fetchOneRaw($conn, "
        SELECT
            u.id,
            u.first_name,
            u.last_name,
            u.email,
            u.department,
            u.job_title,
            u.staff_type,
            u.is_active,
            r.name AS role_name
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = {$supervisorId}
          AND " . roleKeySql('r') . " = 'supervisor'
          AND u.is_active = 1
          AND u.company_id = {$scopeCompanyId}
        LIMIT 1
    ");

    if (!$supervisor) {
        throw new Exception("Selected supervisor was not found for this appraisal cycle.", 404);
    }

    $where = [
        roleKeySql('sr') . " = 'staff'",
        "s.company_id = {$scopeCompanyId}",
        "s.is_active = 1",
    ];

    if ($search !== '') {
        $where[] = "(
            s.first_name LIKE '%{$search}%'
            OR s.last_name LIKE '%{$search}%'
            OR s.email LIKE '%{$search}%'
            OR s.staff_id LIKE '%{$search}%'
            OR s.department LIKE '%{$search}%'
            OR s.job_title LIKE '%{$search}%'
            OR s.unique_ref LIKE '%{$search}%'
        )";
    }

    if ($department !== '') {
        $where[] = "s.department = '{$department}'";
    }

    if ($staffType !== '') {
        $where[] = "s.staff_type = '{$staffType}'";
    }

    if ($assignmentStatus === 'assigned') {
        $where[] = "cur.id IS NOT NULL";
    } elseif ($assignmentStatus === 'unassigned') {
        $where[] = "cur.id IS NULL AND other_assign.staff_id IS NULL";
    } elseif ($assignmentStatus === 'other') {
        $where[] = "cur.id IS NULL AND other_assign.staff_id IS NOT NULL";
    }

    $whereSql = implode(" AND ", $where);

    /**
     * IMPORTANT:
     * supervisor_assignments uses staff_id.
     * appraisals uses staff_user_id.
     */
    $assignmentJoins = "
        LEFT JOIN supervisor_assignments cur
            ON cur.staff_id = s.id
           AND cur.cycle_id = {$cycleId}
           AND cur.supervisor_id = {$supervisorId}

        LEFT JOIN (
            SELECT
                sa.staff_id,
                GROUP_CONCAT(
                    TRIM(CONCAT(COALESCE(sup.first_name, ''), ' ', COALESCE(sup.last_name, '')))
                    SEPARATOR ', '
                ) AS other_supervisor_names
            FROM supervisor_assignments sa
            INNER JOIN users sup ON sup.id = sa.supervisor_id
            INNER JOIN roles sup_role ON sup_role.id = sup.role_id
            WHERE sa.cycle_id = {$cycleId}
              AND sa.supervisor_id <> {$supervisorId}
              AND " . roleKeySql('sup_role') . " = 'supervisor'
            GROUP BY sa.staff_id
        ) other_assign ON other_assign.staff_id = s.id
    ";

    $baseFrom = "
        FROM users s
        INNER JOIN roles sr ON sr.id = s.role_id
        {$assignmentJoins}
    ";

    $countRow = fetchOneRaw($conn, "
        SELECT COUNT(*) AS total
        {$baseFrom}
        WHERE {$whereSql}
    ");

    $total = (int) ($countRow['total'] ?? 0);
    $totalPages = max((int) ceil($total / $limit), 1);

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $limit;
    }

    $staffRows = fetchAllRaw($conn, "
        SELECT
            s.id,
            s.first_name,
            s.last_name,
            s.fullname,
            s.email,
            s.staff_id,
            s.unique_ref,
            s.department,
            s.job_title,
            s.staff_type,
            s.location,
            s.date_of_joining,
            s.is_active,
            sr.name AS role_name,

            CASE WHEN cur.id IS NULL THEN 0 ELSE 1 END AS assigned_to_current,
            CASE WHEN other_assign.staff_id IS NULL THEN 0 ELSE 1 END AS assigned_to_other,
            COALESCE(other_assign.other_supervisor_names, '') AS other_supervisor_names,

            CASE WHEN appr.staff_user_id IS NULL THEN 0 ELSE 1 END AS is_appraised,
            COALESCE(appr.status, '') AS appraisal_status

        {$baseFrom}

        LEFT JOIN (
            SELECT
                staff_user_id,
                MAX(status) AS status
            FROM appraisals
            WHERE cycle_id = {$cycleId}
            GROUP BY staff_user_id
        ) appr ON appr.staff_user_id = s.id

        WHERE {$whereSql}

        ORDER BY {$sortColumn} {$sortOrder}, s.id ASC
        LIMIT {$limit} OFFSET {$offset}
    ");

    $statsWhere = [
        roleKeySql('sr') . " = 'staff'",
        "s.company_id = {$scopeCompanyId}",
        "s.is_active = 1",
    ];

    if ($department !== '') {
        $statsWhere[] = "s.department = '{$department}'";
    }

    if ($staffType !== '') {
        $statsWhere[] = "s.staff_type = '{$staffType}'";
    }

    $statsWhereSql = implode(" AND ", $statsWhere);

    $stats = fetchOneRaw($conn, "
        SELECT
            COALESCE(SUM(CASE WHEN cur.id IS NOT NULL THEN 1 ELSE 0 END), 0) AS assigned_count,
            COALESCE(SUM(CASE WHEN cur.id IS NULL AND other_assign.staff_id IS NULL THEN 1 ELSE 0 END), 0) AS unassigned_count,
            COALESCE(SUM(CASE WHEN cur.id IS NULL AND other_assign.staff_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS assigned_to_other_count,
            COALESCE(SUM(CASE WHEN appr.staff_user_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS appraised_count
        FROM users s
        INNER JOIN roles sr ON sr.id = s.role_id

        {$assignmentJoins}

        LEFT JOIN (
            SELECT staff_user_id
            FROM appraisals
            WHERE cycle_id = {$cycleId}
            GROUP BY staff_user_id
        ) appr ON appr.staff_user_id = s.id

        WHERE {$statsWhereSql}
    ") ?: [];

    $summary = fetchOneRaw($conn, "
        SELECT
            COUNT(sa.id) AS total_subordinates,
            COALESCE(SUM(CASE WHEN appr.staff_user_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS appraised_count,
            CASE
                WHEN COUNT(sa.id) = 0 THEN 0
                ELSE ROUND((COALESCE(SUM(CASE WHEN appr.staff_user_id IS NOT NULL THEN 1 ELSE 0 END), 0) / COUNT(sa.id)) * 100, 1)
            END AS progress_percent,
            CASE WHEN onboard.id IS NULL THEN 0 ELSE 1 END AS is_onboarded
        FROM supervisor_assignments sa
        LEFT JOIN (
            SELECT staff_user_id
            FROM appraisals
            WHERE cycle_id = {$cycleId}
            GROUP BY staff_user_id
        ) appr ON appr.staff_user_id = sa.staff_id
        LEFT JOIN supervisor_onboarding onboard
            ON onboard.supervisor_id = sa.supervisor_id
           AND onboard.cycle_id = sa.cycle_id
        WHERE sa.supervisor_id = {$supervisorId}
          AND sa.cycle_id = {$cycleId}
    ") ?: [];

    $assignedCount  = (int) ($stats['assigned_count'] ?? 0);
    $appraisedCount = (int) ($stats['appraised_count'] ?? 0);
    $pendingCount   = max($assignedCount - $appraisedCount, 0);
    $progress       = $assignedCount > 0 ? round(($appraisedCount / $assignedCount) * 100, 1) : 0;

    $supervisorMeta = array_merge($supervisor, [
        'total_subordinates' => (int) ($summary['total_subordinates'] ?? 0),
        'appraised_count'    => (int) ($summary['appraised_count'] ?? 0),
        'progress_percent'   => (float) ($summary['progress_percent'] ?? 0),
        'is_onboarded'       => (int) ($summary['is_onboarded'] ?? 0),
    ]);

    $meta = [
        'total'                   => $total,
        'page'                    => $page,
        'limit'                   => $limit,
        'total_pages'             => $totalPages,
        'assigned_count'          => $assignedCount,
        'unassigned_count'        => (int) ($stats['unassigned_count'] ?? 0),
        'assigned_to_other_count' => (int) ($stats['assigned_to_other_count'] ?? 0),
        'appraised_count'         => $appraisedCount,
        'pending_count'           => $pendingCount,
        'progress_percent'        => $progress,
        'cycle'                   => [
            'id'        => (int) $cycle['id'],
            'title'     => $cycle['title'],
            'year'      => $cycle['year'],
            'is_active' => (int) $cycle['is_active'],
        ],
        'supervisor'              => $supervisorMeta,
    ];

    jsonResponse('Success', 'Supervisor assignment staff fetched successfully.', $staffRows, $meta);

} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;

    jsonResponse('Failed', $e->getMessage(), [], [], $code);
}