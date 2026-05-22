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
    $clause       = buildCompanyWhereClause($companyScope, 'gq');

    // Filters
    $sectionId = isset($_GET['section_id']) ? (int) $_GET['section_id'] : null;
    $cycleId   = isset($_GET['cycle_id'])   ? (int) $_GET['cycle_id']   : null;
    $isActive  = isset($_GET['is_active'])  ? (int) $_GET['is_active']  : null;
    $search    = isset($_GET['search'])     ? trim($_GET['search'])     : '';

    // Pagination
    $limit  = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
    $page   = isset($_GET['page'])  ? (int) $_GET['page']  : 1;
    if ($limit <= 0) $limit = 20;
    if ($page  <= 0) $page  = 1;
    $offset = ($page - 1) * $limit;

    // Sorting
    $allowedSort = ['id', 'sort_order', 'created_at'];
    $sortBy    = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSort)
                    ? $_GET['sortBy'] : 'sort_order';
    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === 'DESC'
                    ? 'DESC' : 'ASC';

    // Base query
    $baseQuery = "
        FROM general_questions gq
        INNER JOIN appraisal_sections s  ON s.id  = gq.section_id
        INNER JOIN appraisal_cycles ac   ON ac.id = s.cycle_id
        INNER JOIN companies c           ON c.id  = gq.company_id
        WHERE 1=1
    ";
    $params = [];
    $types  = "";

    // Company scope
    if ($clause['value'] !== null) {
        $baseQuery .= " AND gq.company_id = ?";
        $params[]   = $clause['value'];
        $types     .= "i";
    }

    if ($sectionId) {
        $baseQuery .= " AND gq.section_id = ?";
        $params[]   = $sectionId;
        $types     .= "i";
    }

    if ($cycleId) {
        $baseQuery .= " AND s.cycle_id = ?";
        $params[]   = $cycleId;
        $types     .= "i";
    }

    if ($isActive !== null) {
        $baseQuery .= " AND gq.is_active = ?";
        $params[]   = $isActive;
        $types     .= "i";
    }

    if (!empty($search)) {
        $baseQuery .= " AND gq.question_text LIKE ?";
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
            gq.id,
            gq.question_text,
            gq.sort_order,
            gq.is_active,
            gq.created_at,
            gq.updated_at,
            s.id     AS section_id,
            s.code   AS section_code,
            s.label  AS section_label,
            s.weight AS section_weight,
            s.type   AS section_type,
            ac.id    AS cycle_id,
            ac.year  AS cycle_year,
            ac.title AS cycle_title,
            c.id     AS company_id,
            c.code   AS company_code,
            c.name   AS company_name
        " . $baseQuery . "
        ORDER BY gq.{$sortBy} {$sortOrder}
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
        "message" => "General questions fetched successfully",
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
                "cycle_id"      => $cycleId,
                "is_active"     => $isActive,
                "search"        => $search ?: null,
                "company_scope" => $companyScope,
            ]
        ]
    ]);

} catch (Exception $e) {
    error_log("GetGeneralQuestions Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
