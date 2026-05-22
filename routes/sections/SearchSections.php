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

    $search   = isset($_GET['search'])    ? trim($_GET['search'])    : '';
    $cycleId  = isset($_GET['cycle_id'])  ? (int) $_GET['cycle_id'] : null;
    $type     = isset($_GET['type'])      ? trim($_GET['type'])      : null;
    $isActive = isset($_GET['is_active']) ? (int) $_GET['is_active']: 1; // default active only

    $conditions = ["1=1"];
    $params     = [];
    $types      = "";

    if ($clause['value'] !== null) {
        $conditions[] = "s.company_id = ?";
        $params[]     = $clause['value'];
        $types       .= "i";
    }

    if ($cycleId) {
        $conditions[] = "s.cycle_id = ?";
        $params[]     = $cycleId;
        $types       .= "i";
    }

    if ($type && in_array($type, ['kpi', 'general'])) {
        $conditions[] = "s.type = ?";
        $params[]     = $type;
        $types       .= "s";
    }

    $conditions[] = "s.is_active = ?";
    $params[]     = $isActive;
    $types       .= "i";

    if (!empty($search)) {
        $conditions[] = "(s.code LIKE ? OR s.label LIKE ?)";
        $like         = "%" . $search . "%";
        $params[]     = $like;
        $params[]     = $like;
        $types       .= "ss";
    }

    $sql = "
        SELECT
            s.id,
            s.code,
            s.label,
            s.description,
            s.type,
            s.weight,
            s.sort_order,
            ac.id    AS cycle_id,
            ac.year  AS cycle_year,
            c.id     AS company_id,
            c.code   AS company_code
        FROM appraisal_sections s
        INNER JOIN appraisal_cycles ac ON ac.id = s.cycle_id
        INNER JOIN companies c         ON c.id  = s.company_id
        WHERE " . implode(" AND ", $conditions) . "
        ORDER BY s.sort_order ASC, s.code ASC
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
        "message" => "Sections fetched successfully",
        "data"    => $data,
        "meta"    => [
            "count"    => count($data),
            "cycle_id" => $cycleId,
            "type"     => $type,
            "search"   => $search ?: null,
        ]
    ]);

} catch (Exception $e) {
    error_log("SearchSections Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
