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
    $cycleId    = isset($_GET['cycle_id'])   ? (int) $_GET['cycle_id'] : 0;
    $limit      = isset($_GET['limit'])      ? (int) $_GET['limit'] : 500;
    $limit      = max(50, min($limit, 1000));

    $staffScopeFilter = null;
    if (($userData['role'] ?? '') === 'admin') {
        $adminScope = $userData['staff_scope'] ?? 'All';
        if ($adminScope !== 'All') $staffScopeFilter = $adminScope;
    }

    $conditions = [appraiserRoleWhere('r'), "u.is_active = 1"];
    $params     = [];
    $types      = "";

    if ($clause['value'] !== null) {
        $conditions[] = "u.company_id = ?";
        $params[]     = $clause['value'];
        $types       .= "i";
    }
    if ($department) { $conditions[] = "u.department = ?"; $params[] = $department; $types .= "s"; }
    if ($search !== '') {
        $conditions[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.department LIKE ? OR u.job_title LIKE ?)";
        $like = "%" . $search . "%";
        $params = array_merge($params, [$like, $like, $like, $like, $like]);
        $types .= "sssss";
    }

    $cycleAssignWhere = $cycleId > 0 ? "WHERE cycle_id = {$cycleId}" : "";
    $cycleAppWhere = $cycleId > 0 ? "WHERE cycle_id = {$cycleId}" : "";

    $sql = "
        SELECT
            u.id,
            r.name AS role_name,
            u.first_name,
            u.last_name,
            u.fullname,
            u.email,
            u.department,
            u.job_title,
            u.staff_type,
            c.id   AS company_id,
            c.code AS company_code,
            c.name AS company_name,
            COALESCE(ass.assigned_count, 0) AS assigned_count,
            COALESCE(ass.assigned_count, 0) AS total_subordinates,
            COALESCE(app.appraised_count, 0) AS appraised_count,
            GREATEST(COALESCE(ass.assigned_count, 0) - COALESCE(app.appraised_count, 0), 0) AS pending_count,
            CASE WHEN COALESCE(ass.assigned_count, 0) = 0 THEN 0 ELSE ROUND((COALESCE(app.appraised_count, 0) / COALESCE(ass.assigned_count, 0)) * 100, 1) END AS progress_percent
        FROM users u
        INNER JOIN roles r     ON r.id = u.role_id
        INNER JOIN companies c ON c.id = u.company_id
        LEFT JOIN (
            SELECT supervisor_id, COUNT(*) AS assigned_count
            FROM supervisor_assignments
            {$cycleAssignWhere}
            GROUP BY supervisor_id
        ) ass ON ass.supervisor_id = u.id
        LEFT JOIN (
            SELECT supervisor_id, COUNT(DISTINCT staff_user_id) AS appraised_count
            FROM appraisals
            {$cycleAppWhere}
            GROUP BY supervisor_id
        ) app ON app.supervisor_id = u.id
        WHERE " . implode(" AND ", $conditions) . "
        ORDER BY u.first_name ASC, u.last_name ASC
        LIMIT {$limit}
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
        "meta"    => ["count" => count($data), "search" => $search ?: null, "department" => $department, "cycle_id" => $cycleId]
    ]);
} catch (Exception $e) {
    error_log("SearchSupervisors Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
