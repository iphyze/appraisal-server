<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 400);
    }

    $userData     = requireRoles(['super_admin', 'admin']);
    $companyScope = resolveCompanyScope($userData);
    $clause       = buildCompanyWhereClause($companyScope, 'u');

    // Filters
    $department = isset($_GET['department']) ? trim($_GET['department']) : null;
    $staffType  = isset($_GET['staff_type']) ? trim($_GET['staff_type']) : null;
    $isActive   = isset($_GET['is_active'])  ? (int) $_GET['is_active'] : null;
    $cycleId    = isset($_GET['cycle_id'])   ? (int) $_GET['cycle_id']  : null;
    $search     = isset($_GET['search'])     ? trim($_GET['search'])    : '';

    // Admin staff_scope restriction
    $staffScopeFilter = null;
    if ($userData['role'] === 'admin') {
        $adminScope = $userData['staff_scope'] ?? 'All';
        if ($adminScope !== 'All') {
            $staffScopeFilter = $adminScope;
        }
    }

    // Pagination
    $limit  = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
    $page   = isset($_GET['page'])  ? (int) $_GET['page']  : 1;
    if ($limit <= 0) $limit = 20;
    if ($page  <= 0) $page  = 1;
    $offset = ($page - 1) * $limit;

    // Sorting
    $allowedSort = ['id', 'first_name', 'last_name', 'department', 'created_at'];
    $sortBy    = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSort)
                    ? $_GET['sortBy'] : 'first_name';
    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === 'DESC'
                    ? 'DESC' : 'ASC';

    // Base query
    // Base query — split into joins and conditions so extra JOINs can be added in dataQuery
    $baseJoins = "
        FROM users u
        INNER JOIN roles r     ON r.id = u.role_id
        INNER JOIN companies c ON c.id = u.company_id
    ";
    $baseWhere = "WHERE r.name = 'supervisor'";
    $baseQuery = $baseJoins . $baseWhere;
    $params = [];
    $types  = "";

    // Company scope
    if ($clause['value'] !== null) {
        $baseQuery .= " AND u.company_id = ?";
        $params[]   = $clause['value'];
        $types     .= "i";
    }

    if ($department) {
        $baseQuery .= " AND u.department = ?";
        $params[]   = $department;
        $types     .= "s";
    }

    if ($staffType) {
        $baseQuery .= " AND u.staff_type = ?";
        $params[]   = $staffType;
        $types     .= "s";
    }

    if ($staffScopeFilter) {
        $baseQuery .= " AND u.staff_type = ?";
        $params[]   = $staffScopeFilter;
        $types     .= "s";
    }

    if ($isActive !== null) {
        $baseQuery .= " AND u.is_active = ?";
        $params[]   = $isActive;
        $types     .= "i";
    }

    if (!empty($search)) {
        $baseQuery .= " AND (
            u.first_name LIKE ? OR u.last_name  LIKE ?
            OR u.email   LIKE ? OR u.department LIKE ?
        )";
        $like      = "%" . $search . "%";
        $params    = array_merge($params, [$like, $like, $like, $like]);
        $types    .= "ssss";
    }

    // Count
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total " . $baseQuery);
    if (!$countStmt) throw new Exception("Database error: " . $conn->error, 500);
    if (!empty($params)) $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // Fetch with subordinate stats per active cycle
    $cycleJoin = $cycleId
        ? "AND sa.cycle_id = {$cycleId}"
        : "AND ac.is_active = 1";

    $dataQuery = "
        SELECT
            u.id,
            u.staff_id,
            u.first_name,
            u.last_name,
            u.email,
            u.username,
            u.department,
            u.job_title,
            u.staff_type,
            u.location,
            u.date_of_joining,
            u.is_active,
            u.onboarded_at,
            u.last_login_at,
            c.id    AS company_id,
            c.code  AS company_code,
            c.name  AS company_name,
            COUNT(DISTINCT sa.staff_id)      AS total_subordinates,
            COUNT(DISTINCT ap.staff_user_id) AS appraised_count,
            MAX(so.onboarded_at)             AS cycle_onboarded_at
        " . $baseJoins . "
        LEFT JOIN supervisor_assignments sa ON sa.supervisor_id = u.id
        LEFT JOIN appraisal_cycles ac       ON ac.id = sa.cycle_id {$cycleJoin}
        LEFT JOIN appraisals ap             ON ap.supervisor_id = u.id
                                          AND ap.cycle_id = sa.cycle_id
        LEFT JOIN supervisor_onboarding so  ON so.supervisor_id = u.id
                                          AND so.cycle_id = sa.cycle_id
        " . $baseWhere . "
        GROUP BY u.id
        ORDER BY u.{$sortBy} {$sortOrder}
        LIMIT ? OFFSET ?
    ";

    $dataStmt = $conn->prepare($dataQuery);
    if (!$dataStmt) throw new Exception("Database error: " . $conn->error, 500);

    $types   .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    $dataStmt->bind_param($types, ...$params);
    $dataStmt->execute();
    $data = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    // Add pending_count and progress % to each supervisor
    foreach ($data as &$sup) {
        $sup['pending_count']    = max(0, (int)$sup['total_subordinates'] - (int)$sup['appraised_count']);
        $sup['progress_percent'] = $sup['total_subordinates'] > 0
            ? round(($sup['appraised_count'] / $sup['total_subordinates']) * 100, 1)
            : 0;
        $sup['is_onboarded']     = !empty($sup['cycle_onboarded_at']);
    }
    unset($sup);

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Supervisors fetched successfully",
        "data"    => $data,
        "meta"    => [
            "total"       => $total,
            "page"        => $page,
            "limit"       => $limit,
            "total_pages" => (int) ceil($total / $limit),
            "sortBy"      => $sortBy,
            "sortOrder"   => $sortOrder,
            "filters"     => [
                "department"    => $department,
                "staff_type"    => $staffType,
                "is_active"     => $isActive,
                "cycle_id"      => $cycleId,
                "search"        => $search ?: null,
                "company_scope" => $companyScope,
            ]
        ]
    ]);

} catch (Exception $e) {
    error_log("GetSupervisors Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
