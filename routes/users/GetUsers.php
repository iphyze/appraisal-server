<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 400);
    }

    // Auth — super_admin, admin only
    $userData     = requireRoles(['super_admin', 'admin']);
    $companyScope = resolveCompanyScope($userData);
    $clause       = buildCompanyWhereClause($companyScope, 'u');

    // ── Pagination ────────────────────────────────────────────────────────────
    if (!isset($_GET['limit']) || !isset($_GET['page'])) {
        throw new Exception("Missing required parameters: 'limit' and 'page'", 400);
    }

    $limit  = (int) $_GET['limit'];
    $page   = (int) $_GET['page'];

    if ($limit <= 0 || $page <= 0) {
        throw new Exception("'limit' and 'page' must be positive integers", 400);
    }

    $offset = ($page - 1) * $limit;

    // ── Sorting ───────────────────────────────────────────────────────────────
    $allowedSortFields = ['id', 'first_name', 'last_name', 'email', 'role', 'department', 'created_at'];
    $sortBy    = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields)
                    ? $_GET['sortBy']
                    : 'id';
    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === 'ASC'
                    ? 'ASC'
                    : 'DESC';

    // ── Filters ───────────────────────────────────────────────────────────────
    $search     = isset($_GET['search'])     ? trim($_GET['search'])     : null;
    $roleFilter = isset($_GET['role'])       ? trim($_GET['role'])       : null;
    $deptFilter = isset($_GET['department']) ? trim($_GET['department']) : null;
    $typeFilter = isset($_GET['staff_type']) ? trim($_GET['staff_type']) : null;
    $appraiseeOnly = isset($_GET['appraisee_only']) && (int) $_GET['appraisee_only'] === 1;

    /**
     * Admin scope restriction:
     * An admin with staff_scope = 'Local'      sees only Local staff
     * An admin with staff_scope = 'Expatriate' sees only Expatriate staff
     * An admin with staff_scope = 'All'        sees everyone
     * super_admin always sees everyone
     */
    $staffScopeFilter = null;
    if ($userData['role'] === 'admin') {
        $adminScope = $userData['staff_scope'] ?? 'All';
        if ($adminScope !== 'All') {
            $staffScopeFilter = $adminScope; // 'Local' or 'Expatriate'
        }
    }

    // ── Build base query ──────────────────────────────────────────────────────
    $baseQuery = "
        FROM users u
        INNER JOIN roles r     ON r.id = u.role_id
        INNER JOIN companies c ON c.id = u.company_id
        WHERE 1=1
    ";

    $params = [];
    $types  = "";

    // Company scope (from resolveCompanyScope)
    $baseQuery .= $clause['sql'];
    if ($clause['value'] !== null) {
        $params[] = $clause['value'];
        $types   .= $clause['type'];
    }

    if ($appraiseeOnly) {
        $baseQuery .= " AND LOWER(REPLACE(TRIM(r.name), ' ', '_')) <> 'super_admin'";
    }

    // Role filter
    if ($roleFilter) {
        $baseQuery .= " AND r.name = ?";
        $params[]   = $roleFilter;
        $types     .= "s";
    }

    // Department filter
    if ($deptFilter) {
        $baseQuery .= " AND u.department = ?";
        $params[]   = $deptFilter;
        $types     .= "s";
    }

    // Staff type filter (Local / Expatriate)
    if ($typeFilter) {
        $baseQuery .= " AND u.staff_type = ?";
        $params[]   = $typeFilter;
        $types     .= "s";
    }

    // Admin staff_scope restriction
    if ($staffScopeFilter) {
        // Staff scope restricts regular staff only. Peer admins/supervisors remain visible.
        $baseQuery .= " AND (" . "LOWER(REPLACE(TRIM(r.name), ' ', '_')) <> 'staff'" . " OR u.staff_type = ?)";
        $params[]   = $staffScopeFilter;
        $types     .= "s";
    }

    // Search (name, email, department, job_title, staff_id)
    if ($search) {
        $baseQuery .= " AND (
            u.first_name LIKE ?
            OR u.last_name  LIKE ?
            OR u.email      LIKE ?
            OR u.department LIKE ?
            OR u.job_title  LIKE ?
            OR u.staff_id   LIKE ?
        )";
        $like      = "%" . $search . "%";
        $params    = array_merge($params, [$like, $like, $like, $like, $like, $like]);
        $types    .= "ssssss";
    }

    // ── Count total ───────────────────────────────────────────────────────────
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total " . $baseQuery);
    if (!$countStmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // ── Fetch paginated data ──────────────────────────────────────────────────
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
            u.staff_scope,
            u.location,
            u.unique_ref,
            u.date_of_joining,
            u.is_active,
            u.last_login_at,
            u.must_change_password,
            u.created_at,
            r.name  AS role,
            c.id    AS company_id,
            c.code  AS company_code,
            c.name  AS company_name
        " . $baseQuery . "
        ORDER BY u.{$sortBy} {$sortOrder}
        LIMIT ? OFFSET ?
    ";

    $dataStmt = $conn->prepare($dataQuery);
    if (!$dataStmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $types   .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    $dataStmt->bind_param($types, ...$params);
    $dataStmt->execute();
    $data = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Users fetched successfully",
        "data"    => $data,
        "meta"    => [
            "total"        => $total,
            "page"         => $page,
            "limit"        => $limit,
            "total_pages"  => (int) ceil($total / $limit),
            "sortBy"       => $sortBy,
            "sortOrder"    => $sortOrder,
            "search"       => $search,
            "filters"      => [
                "role"         => $roleFilter,
                "department"   => $deptFilter,
                "staff_type"   => $typeFilter,
                "company_id"   => $companyScope,
            ]
        ]
    ]);

} catch (Exception $e) {
    error_log("GetUsers Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}