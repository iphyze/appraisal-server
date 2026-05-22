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

    // Admin staff_scope restriction
    $staffScopeFilter = null;
    if ($userData['role'] === 'admin') {
        $adminScope = $userData['staff_scope'] ?? 'All';
        if ($adminScope !== 'All') {
            $staffScopeFilter = $adminScope;
        }
    }

    // Optional search query — frontend sends ?search=john
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    // Optional role filter — frontend sends ?role=supervisor
    $roleFilter = isset($_GET['role']) ? trim($_GET['role']) : '';

    // ── Base query ────────────────────────────────────────────────────────────
    $sql = "
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
            u.updated_at,
            r.name  AS role,
            c.id    AS company_id,
            c.code  AS company_code,
            c.name  AS company_name
        FROM users u
        INNER JOIN roles r     ON r.id = u.role_id
        INNER JOIN companies c ON c.id = u.company_id
    ";

    $params     = [];
    $types      = "";
    $appraiseeOnly = isset($_GET['appraisee_only']) && (int) $_GET['appraisee_only'] === 1;
    $conditions = ["1=1"];

    if ($appraiseeOnly) {
        $conditions[] = "LOWER(REPLACE(TRIM(r.name), ' ', '_')) <> 'super_admin'";
    }

    // Company scope
    if ($clause['value'] !== null) {
        $conditions[] = "u.company_id = ?";
        $params[]     = $clause['value'];
        $types       .= "i";
    }

    // Admin staff_scope restriction
    if ($staffScopeFilter) {
        $conditions[] = "(LOWER(REPLACE(TRIM(r.name), ' ', '_')) <> 'staff' OR u.staff_type = ?)";
        $params[]     = $staffScopeFilter;
        $types       .= "s";
    }

    // Role filter
    if (!empty($roleFilter)) {
        $conditions[] = "r.name = ?";
        $params[]     = $roleFilter;
        $types       .= "s";
    }

    // Search across name, email, username, staff_id, department, job_title
    if (!empty($search)) {
        $conditions[] = "(
            u.first_name  LIKE ?
            OR u.last_name  LIKE ?
            OR u.email      LIKE ?
            OR u.username   LIKE ?
            OR u.staff_id   LIKE ?
            OR u.department LIKE ?
            OR u.job_title  LIKE ?
        )";
        $like      = "%" . $search . "%";
        $params    = array_merge($params, array_fill(0, 7, $like));
        $types    .= "sssssss";
    }

    $sql .= " WHERE " . implode(" AND ", $conditions);
    $sql .= " ORDER BY u.first_name ASC, u.last_name ASC LIMIT 100";

    // ── Execute ───────────────────────────────────────────────────────────────
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Users fetched successfully",
        "data"    => $data,
        "meta"    => [
            "count"        => count($data),
            "search"       => $search,
            "role_filter"  => $roleFilter ?: null,
            "company_scope"=> $companyScope,
        ]
    ]);

} catch (Exception $e) {
    error_log("SearchUsers Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}