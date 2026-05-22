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
    $clause       = buildCompanyWhereClause($companyScope, 'kq');

    // Filters
    $sectionId     = isset($_GET['section_id'])    ? (int) $_GET['section_id']    : null;
    $department    = isset($_GET['department'])     ? trim($_GET['department'])     : null;
    $supervisorId  = isset($_GET['supervisor_id'])  ? (int) $_GET['supervisor_id'] : null;
    $staffUserId   = isset($_GET['staff_user_id'])  ? (int) $_GET['staff_user_id'] : null;
    $isActive      = isset($_GET['is_active'])      ? (int) $_GET['is_active']     : null;
    $search        = isset($_GET['search'])         ? trim($_GET['search'])         : '';

    // Pagination
    $limit  = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
    $page   = isset($_GET['page'])  ? (int) $_GET['page']  : 1;
    if ($limit <= 0) $limit = 20;
    if ($page  <= 0) $page  = 1;
    $offset = ($page - 1) * $limit;

    // Sorting
    $allowedSort = ['id', 'department', 'sort_order', 'created_at'];
    $sortBy    = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSort)
                    ? $_GET['sortBy'] : 'sort_order';
    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === 'DESC'
                    ? 'DESC' : 'ASC';

    // Base query
    $baseQuery = "
        FROM kpi_questions kq
        INNER JOIN appraisal_sections s  ON s.id  = kq.section_id
        INNER JOIN appraisal_cycles ac   ON ac.id = s.cycle_id
        INNER JOIN companies c           ON c.id  = kq.company_id
        LEFT  JOIN users sup             ON sup.id = kq.supervisor_id
        LEFT  JOIN users stf             ON stf.id = kq.staff_user_id
        WHERE 1=1
    ";
    $params = [];
    $types  = "";

    // Company scope
    if ($clause['value'] !== null) {
        $baseQuery .= " AND kq.company_id = ?";
        $params[]   = $clause['value'];
        $types     .= "i";
    }

    if ($sectionId) {
        $baseQuery .= " AND kq.section_id = ?";
        $params[]   = $sectionId;
        $types     .= "i";
    }

    if ($department) {
        $baseQuery .= " AND kq.department = ?";
        $params[]   = $department;
        $types     .= "s";
    }

    if ($supervisorId) {
        $baseQuery .= " AND kq.supervisor_id = ?";
        $params[]   = $supervisorId;
        $types     .= "i";
    }

    if ($staffUserId) {
        $baseQuery .= " AND kq.staff_user_id = ?";
        $params[]   = $staffUserId;
        $types     .= "i";
    }

    if ($isActive !== null) {
        $baseQuery .= " AND kq.is_active = ?";
        $params[]   = $isActive;
        $types     .= "i";
    }

    if (!empty($search)) {
        $baseQuery .= " AND kq.question_text LIKE ?";
        $params[]   = "%" . $search . "%";
        $types     .= "s";
    }

    // Count
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total " . $baseQuery);
    if (!$countStmt) throw new Exception("Database error: " . $conn->error, 500);
    if (!empty($params)) $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // Fetch
    $dataQuery = "
        SELECT
            kq.id,
            kq.department,
            kq.question_text,
            kq.sort_order,
            kq.is_active,
            kq.created_at,
            kq.updated_at,
            s.id    AS section_id,
            s.code  AS section_code,
            s.label AS section_label,
            s.weight AS section_weight,
            ac.id   AS cycle_id,
            ac.year AS cycle_year,
            c.id    AS company_id,
            c.code  AS company_code,
            c.name  AS company_name,
            -- Supervisor details (NULL = departmental default)
            kq.supervisor_id,
            CONCAT(sup.first_name, ' ', sup.last_name) AS supervisor_name,
            sup.email                                   AS supervisor_email,
            -- Staff details (NULL = applies to all in group)
            kq.staff_user_id,
            CONCAT(stf.first_name, ' ', stf.last_name) AS staff_name,
            stf.email                                   AS staff_email,
            -- Scope label for easy frontend display
            CASE
                WHEN kq.staff_user_id IS NOT NULL THEN 'individual'
                WHEN kq.supervisor_id IS NOT NULL THEN 'supervisor'
                ELSE 'department'
            END AS scope
        " . $baseQuery . "
        ORDER BY kq.{$sortBy} {$sortOrder}
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

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "KPI questions fetched successfully",
        "data"    => $data,
        "meta"    => [
            "total"       => $total,
            "page"        => $page,
            "limit"       => $limit,
            "total_pages" => (int) ceil($total / $limit),
            "sortBy"      => $sortBy,
            "sortOrder"   => $sortOrder,
            "filters"     => [
                "section_id"    => $sectionId,
                "department"    => $department,
                "supervisor_id" => $supervisorId,
                "staff_user_id" => $staffUserId,
                "is_active"     => $isActive,
                "search"        => $search ?: null,
                "company_scope" => $companyScope,
            ]
        ]
    ]);

} catch (Exception $e) {
    error_log("GetKpiQuestions Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
