<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 400);
    }

    $userData = authenticateUser();

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("Missing required parameter: 'id'.", 400);
    }

    $id = (int) $_GET['id'];
    if ($id <= 0) throw new Exception("Invalid question ID.", 400);

    $stmt = $conn->prepare("
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
        FROM general_questions gq
        INNER JOIN appraisal_sections s ON s.id  = gq.section_id
        INNER JOIN appraisal_cycles ac  ON ac.id = s.cycle_id
        INNER JOIN companies c          ON c.id  = gq.company_id
        WHERE gq.id = ?
        LIMIT 1
    ");
    if (!$stmt) throw new Exception("Database error: " . $conn->error, 500);

    $stmt->bind_param("i", $id);
    $stmt->execute();
    $question = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$question) throw new Exception("General question not found.", 404);

    $companyScope = resolveCompanyScope($userData);
    if ($companyScope !== null && (int) $question['company_id'] !== (int) $companyScope) {
        throw new Exception("Unauthorized: This general question is outside the selected company scope.", 403);
    }

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "General question fetched successfully",
        "data"    => $question
    ]);

} catch (Exception $e) {
    error_log("GetSingleGeneralQuestion Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
