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
    $clause       = buildCompanyWhereClause($companyScope, 's');

    // Filters
    $cycleId   = isset($_GET['cycle_id'])  ? (int) $_GET['cycle_id']  : null;
    $type      = isset($_GET['type'])      ? trim($_GET['type'])      : null;
    $isActive  = isset($_GET['is_active']) ? (int) $_GET['is_active'] : null;
    $search    = isset($_GET['search'])    ? trim($_GET['search'])    : '';

    // Pagination
    $limit  = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
    $page   = isset($_GET['page'])  ? (int) $_GET['page']  : 1;
    if ($limit <= 0) $limit = 20;
    if ($page  <= 0) $page  = 1;
    $offset = ($page - 1) * $limit;

    // Sorting
    $allowedSort = ['id', 'code', 'label', 'weight', 'sort_order', 'created_at'];
    $sortBy    = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSort)
                    ? $_GET['sortBy'] : 'sort_order';
    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === 'DESC'
                    ? 'DESC' : 'ASC';

    // Base query
    $baseQuery = "
        FROM appraisal_sections s
        INNER JOIN appraisal_cycles ac ON ac.id = s.cycle_id
        INNER JOIN companies c         ON c.id  = s.company_id
        WHERE 1=1
    ";
    $params = [];
    $types  = "";

    // Company scope
    if ($clause['value'] !== null) {
        $baseQuery .= " AND s.company_id = ?";
        $params[]   = $clause['value'];
        $types     .= "i";
    }

    if ($cycleId) {
        $baseQuery .= " AND s.cycle_id = ?";
        $params[]   = $cycleId;
        $types     .= "i";
    }

    if ($type && in_array($type, ['kpi', 'general'])) {
        $baseQuery .= " AND s.type = ?";
        $params[]   = $type;
        $types     .= "s";
    }

    if ($isActive !== null) {
        $baseQuery .= " AND s.is_active = ?";
        $params[]   = $isActive;
        $types     .= "i";
    }

    if (!empty($search)) {
        $baseQuery .= " AND (s.code LIKE ? OR s.label LIKE ?)";
        $like       = "%" . $search . "%";
        $params[]   = $like;
        $params[]   = $like;
        $types     .= "ss";
    }

    // Count
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total " . $baseQuery);
    if (!$countStmt) throw new Exception("Database error: " . $conn->error, 500);
    if (!empty($params)) $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // Fetch — include total weight for the cycle so frontend can show remaining %
    $dataQuery = "
        SELECT
            s.id,
            s.code,
            s.label,
            s.description,
            s.type,
            s.weight,
            s.sort_order,
            s.is_active,
            s.created_at,
            s.updated_at,
            ac.id    AS cycle_id,
            ac.year  AS cycle_year,
            ac.title AS cycle_title,
            c.id     AS company_id,
            c.code   AS company_code,
            c.name   AS company_name,
            (
                SELECT COALESCE(SUM(s2.weight), 0)
                FROM appraisal_sections s2
                WHERE s2.cycle_id = s.cycle_id
                  AND s2.company_id = s.company_id
                  AND s2.is_active = 1
            ) AS total_weight_used
        " . $baseQuery . "
        ORDER BY s.{$sortBy} {$sortOrder}
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
        "message" => "Sections fetched successfully",
        "data"    => $data,
        "meta"    => [
            "total"       => $total,
            "page"        => $page,
            "limit"       => $limit,
            "total_pages" => (int) ceil($total / $limit),
            "sortBy"      => $sortBy,
            "sortOrder"   => $sortOrder,
            "filters"     => [
                "cycle_id"     => $cycleId,
                "type"         => $type,
                "is_active"    => $isActive,
                "search"       => $search ?: null,
                "company_scope"=> $companyScope,
            ]
        ]
    ]);

} catch (Exception $e) {
    error_log("GetSections Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
