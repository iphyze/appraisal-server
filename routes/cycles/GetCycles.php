<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 400);
    }

    // All authenticated roles can view cycles
    $userData     = authenticateUser();
    $companyScope = resolveCompanyScope($userData);
    $clause       = buildCompanyWhereClause($companyScope, 'ac');

    // ── Optional filters ──────────────────────────────────────────────────────
    $search    = isset($_GET['search'])    ? trim($_GET['search'])    : '';
    $isActive  = isset($_GET['is_active']) ? (int) $_GET['is_active'] : null;
    $year      = isset($_GET['year'])      ? (int) $_GET['year']      : null;

    // ── Pagination ────────────────────────────────────────────────────────────
    $limit  = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
    $page   = isset($_GET['page'])  ? (int) $_GET['page']  : 1;

    if ($limit <= 0) $limit = 20;
    if ($page  <= 0) $page  = 1;

    $offset = ($page - 1) * $limit;

    // ── Sorting ───────────────────────────────────────────────────────────────
    $allowedSortFields = ['id', 'year', 'title', 'start_date', 'end_date', 'is_active', 'created_at'];
    $sortBy    = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields)
                    ? $_GET['sortBy'] : 'year';
    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === 'ASC'
                    ? 'ASC' : 'DESC';

    // ── Build base query ──────────────────────────────────────────────────────
    $baseQuery = "
        FROM appraisal_cycles ac
        INNER JOIN companies c ON c.id = ac.company_id
        WHERE 1=1
    ";

    $params = [];
    $types  = "";

    // Company scope
    if ($clause['value'] !== null) {
        $baseQuery .= " AND ac.company_id = ?";
        $params[]   = $clause['value'];
        $types     .= "i";
    }

    // is_active filter
    if ($isActive !== null) {
        $baseQuery .= " AND ac.is_active = ?";
        $params[]   = $isActive;
        $types     .= "i";
    }

    // Year filter
    if ($year) {
        $baseQuery .= " AND ac.year = ?";
        $params[]   = $year;
        $types     .= "i";
    }

    // Search by title
    if (!empty($search)) {
        $baseQuery .= " AND ac.title LIKE ?";
        $params[]   = "%" . $search . "%";
        $types     .= "s";
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
            ac.id,
            ac.year,
            ac.title,
            ac.start_date,
            ac.end_date,
            ac.is_active,
            ac.created_at,
            ac.updated_at,
            c.id    AS company_id,
            c.code  AS company_code,
            c.name  AS company_name
        " . $baseQuery . "
        ORDER BY ac.{$sortBy} {$sortOrder}
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
        "message" => "Appraisal cycles fetched successfully",
        "data"    => $data,
        "meta"    => [
            "total"       => $total,
            "page"        => $page,
            "limit"       => $limit,
            "total_pages" => (int) ceil($total / $limit),
            "sortBy"      => $sortBy,
            "sortOrder"   => $sortOrder,
            "filters"     => [
                "search"       => $search ?: null,
                "is_active"    => $isActive,
                "year"         => $year ?: null,
                "company_scope"=> $companyScope,
            ]
        ]
    ]);

} catch (Exception $e) {
    error_log("GetCycles Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}
