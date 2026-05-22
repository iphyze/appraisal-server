<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Bad Request: Only POST method is allowed", 400);
    }

    $userData          = requireRoles(['super_admin', 'admin']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) throw new Exception("Invalid request format.", 400);

    // Required fields
    foreach (['section_id', 'question_text'] as $field) {
        if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    $sectionId    = (int) $data['section_id'];
    $questionText = trim($data['question_text']);
    $sortOrder    = isset($data['sort_order']) ? (int) $data['sort_order'] : 0;
    $isActive     = isset($data['is_active'])  ? (int) $data['is_active']  : 1;

    // company_id
    $companyId = ($loggedInUserRole === 'super_admin' && isset($data['company_id']))
        ? (int) $data['company_id']
        : $loggedInCompanyId;

    // Validate section exists, belongs to company, and is type 'general'
    $sectionStmt = $conn->prepare("
        SELECT id, type, company_id
        FROM appraisal_sections
        WHERE id = ? AND company_id = ?
        LIMIT 1
    ");
    $sectionStmt->bind_param("ii", $sectionId, $companyId);
    $sectionStmt->execute();
    $section = $sectionStmt->get_result()->fetch_assoc();
    $sectionStmt->close();

    if (!$section) {
        throw new Exception("Section not found for this company.", 404);
    }
    if ($section['type'] !== 'general') {
        throw new Exception(
            "General questions can only be added to a section of type 'general'. " .
            "For KPI sections use the /kpi-questions/create route.",
            400
        );
    }

    // Insert
    $insertStmt = $conn->prepare("
        INSERT INTO general_questions
          (company_id, section_id, question_text, sort_order, is_active, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if (!$insertStmt) throw new Exception("Database error: " . $conn->error, 500);

    $insertStmt->bind_param(
        "iisiii",
        $companyId, $sectionId, $questionText,
        $sortOrder, $isActive, $loggedInUserId
    );
    if (!$insertStmt->execute()) {
        throw new Exception("Failed to create question: " . $insertStmt->error, 500);
    }

    $newId = $insertStmt->insert_id;
    $insertStmt->close();

    // Log
    $logStmt = $conn->prepare("
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if ($logStmt) {
        $action      = "create_general_question";
        $targetTable = "general_questions";
        $description = "{$loggedInUserEmail} created general question for section ID: {$sectionId}";
        $logStmt->bind_param("iissis", $companyId, $loggedInUserId, $action, $targetTable, $newId, $description);
        $logStmt->execute();
        $logStmt->close();
    }

    // Fetch created record
    $fetchStmt = $conn->prepare("
        SELECT gq.*, s.code AS section_code, s.label AS section_label,
               s.weight AS section_weight, s.type AS section_type,
               ac.year AS cycle_year, ac.title AS cycle_title
        FROM general_questions gq
        INNER JOIN appraisal_sections s ON s.id  = gq.section_id
        INNER JOIN appraisal_cycles ac  ON ac.id = s.cycle_id
        WHERE gq.id = ? LIMIT 1
    ");
    $fetchStmt->bind_param("i", $newId);
    $fetchStmt->execute();
    $newQuestion = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "General question created successfully",
        "data"    => $newQuestion
    ]);

} catch (Exception $e) {
    error_log("CreateGeneralQuestion Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
