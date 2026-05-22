<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 400);
    }

    // All authenticated roles can search cycles
    $userData     = authenticateUser();
    $companyScope = resolveCompanyScope($userData);
    $clause       = buildCompanyWhereClause($companyScope, 'ac');

    // Optional filters
    $search   = isset($_GET['search'])    ? trim($_GET['search'])    : '';
    $isActive = isset($_GET['is_active']) ? (int) $_GET['is_active'] : null;
    $year     = isset($_GET['year'])      ? (int) $_GET['year']      : null;

    // ── Build query ───────────────────────────────────────────────────────────
    $conditions = ["1=1"];
    $params     = [];
    $types      = "";

    // Company scope
    if ($clause['value'] !== null) {
        $conditions[] = "ac.company_id = ?";
        $params[]     = $clause['value'];
        $types       .= "i";
    }

    // is_active filter — useful to show only the active cycle in dropdowns
    if ($isActive !== null) {
        $conditions[] = "ac.is_active = ?";
        $params[]     = $isActive;
        $types       .= "i";
    }

    // Year filter
    if ($year) {
        $conditions[] = "ac.year = ?";
        $params[]     = $year;
        $types       .= "i";
    }

    // Search by title or year
    if (!empty($search)) {
        $conditions[] = "(ac.title LIKE ? OR ac.year LIKE ?)";
        $like         = "%" . $search . "%";
        $params[]     = $like;
        $params[]     = $like;
        $types       .= "ss";
    }

    $sql = "
        SELECT
            ac.id,
            ac.year,
            ac.title,
            ac.start_date,
            ac.end_date,
            ac.is_active,
            c.id    AS company_id,
            c.code  AS company_code,
            c.name  AS company_name
        FROM appraisal_cycles ac
        INNER JOIN companies c ON c.id = ac.company_id
        WHERE " . implode(" AND ", $conditions) . "
        ORDER BY ac.year DESC
        LIMIT 50
    ";

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
        "message" => "Appraisal cycles fetched successfully",
        "data"    => $data,
        "meta"    => [
            "count"        => count($data),
            "search"       => $search ?: null,
            "is_active"    => $isActive,
            "year"         => $year ?: null,
            "company_scope"=> $companyScope,
        ]
    ]);

} catch (Exception $e) {
    error_log("SearchCycles Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}