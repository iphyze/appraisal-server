<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 400);
    }

    $userData     = authenticateUser();
    $companyScope = resolveCompanyScope($userData);
    $clause       = buildCompanyWhereClause($companyScope, 'u');

    $search     = isset($_GET['search'])     ? trim($_GET['search'])    : '';
    $department = isset($_GET['department']) ? trim($_GET['department']) : null;
    $cycleId    = isset($_GET['cycle_id'])   ? (int) $_GET['cycle_id'] : null;

    // Admin staff_scope restriction
    $staffScopeFilter = null;
    if ($userData['role'] === 'admin') {
        $adminScope = $userData['staff_scope'] ?? 'All';
        if ($adminScope !== 'All') {
            $staffScopeFilter = $adminScope;
        }
    }

    $conditions = ["r.name = 'supervisor'", "u.is_active = 1"];
    $params     = [];
    $types      = "";

    if ($clause['value'] !== null) {
        $conditions[] = "u.company_id = ?";
        $params[]     = $clause['value'];
        $types       .= "i";
    }

    if ($department) {
        $conditions[] = "u.department = ?";
        $params[]     = $department;
        $types       .= "s";
    }

    if ($staffScopeFilter) {
        $conditions[] = "u.staff_type = ?";
        $params[]     = $staffScopeFilter;
        $types       .= "s";
    }

    if (!empty($search)) {
        $conditions[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
        $like         = "%" . $search . "%";
        $params       = array_merge($params, [$like, $like, $like]);
        $types       .= "sss";
    }

    $sql = "
        SELECT
            u.id,
            u.first_name,
            u.last_name,
            u.email,
            u.department,
            u.job_title,
            u.staff_type,
            c.id   AS company_id,
            c.code AS company_code
        FROM users u
        INNER JOIN roles r     ON r.id = u.role_id
        INNER JOIN companies c ON c.id = u.company_id
        WHERE " . implode(" AND ", $conditions) . "
        ORDER BY u.first_name ASC, u.last_name ASC
        LIMIT 50
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception("Database error: " . $conn->error, 500);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Supervisors fetched successfully",
        "data"    => $data,
        "meta"    => [
            "count"      => count($data),
            "search"     => $search ?: null,
            "department" => $department,
        ]
    ]);

} catch (Exception $e) {
    error_log("SearchSupervisors Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
