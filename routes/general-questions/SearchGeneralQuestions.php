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

    $search    = isset($_GET['search'])     ? trim($_GET['search'])    : '';
    $sectionId = isset($_GET['section_id']) ? (int)$_GET['section_id']: null;
    $cycleId   = isset($_GET['cycle_id'])   ? (int)$_GET['cycle_id']  : null;

    $conditions = ["1=1", "gq.is_active = 1"];
    $params     = [];
    $types      = "";

    if ($clause['value'] !== null) {
        $conditions[] = "gq.company_id = ?";
        $params[]     = $clause['value'];
        $types       .= "i";
    }

    if ($sectionId) {
        $conditions[] = "gq.section_id = ?";
        $params[]     = $sectionId;
        $types       .= "i";
    }

    if ($cycleId) {
        $conditions[] = "s.cycle_id = ?";
        $params[]     = $cycleId;
        $types       .= "i";
    }

    if (!empty($search)) {
        $conditions[] = "gq.question_text LIKE ?";
        $params[]     = "%" . $search . "%";
        $types       .= "s";
    }

    $sql = "
        SELECT
            gq.id,
            gq.question_text,
            gq.sort_order,
            s.id     AS section_id,
            s.code   AS section_code,
            s.label  AS section_label,
            s.weight AS section_weight,
            ac.id    AS cycle_id,
            ac.year  AS cycle_year
        FROM general_questions gq
        INNER JOIN appraisal_sections s ON s.id  = gq.section_id
        INNER JOIN appraisal_cycles ac  ON ac.id = s.cycle_id
        WHERE " . implode(" AND ", $conditions) . "
        ORDER BY gq.sort_order ASC
        LIMIT 100
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
        "message" => "General questions fetched successfully",
        "data"    => $data,
        "meta"    => [
            "count"      => count($data),
            "section_id" => $sectionId,
            "cycle_id"   => $cycleId,
            "search"     => $search ?: null,
        ]
    ]);

} catch (Exception $e) {
    error_log("SearchGeneralQuestions Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
