<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Bad Request: Only PUT method is allowed", 400);
    }

    $userData          = requireRoles(['super_admin', 'admin']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) throw new Exception("Invalid request format.", 400);
    if (!isset($data['id']) || !is_numeric($data['id'])) {
        throw new Exception("Field 'id' is required.", 400);
    }

    $questionId = (int) $data['id'];

    // Fetch existing
    $checkStmt = $conn->prepare("SELECT * FROM general_questions WHERE id = ? LIMIT 1");
    $checkStmt->bind_param("i", $questionId);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$existing) throw new Exception("General question not found.", 404);

    if (
        $loggedInUserRole !== 'super_admin' &&
        (int) $existing['company_id'] !== $loggedInCompanyId
    ) {
        throw new Exception("Unauthorized: You can only update questions within your company.", 403);
    }

    $updateFields = [];
    $params       = [];
    $types        = "";

    if (isset($data['question_text']) && trim($data['question_text']) !== '') {
        $updateFields[] = "question_text = ?";
        $params[]       = trim($data['question_text']);
        $types         .= "s";
    }

    if (isset($data['sort_order'])) {
        $updateFields[] = "sort_order = ?";
        $params[]       = (int) $data['sort_order'];
        $types         .= "i";
    }

    if (isset($data['is_active'])) {
        $updateFields[] = "is_active = ?";
        $params[]       = (int) $data['is_active'];
        $types         .= "i";
    }

    // Allow moving question to a different section
    // (must be same company and type = general)
    if (isset($data['section_id'])) {
        $newSectionId = (int) $data['section_id'];
        $secStmt = $conn->prepare("
            SELECT id, type FROM appraisal_sections
            WHERE id = ? AND company_id = ? LIMIT 1
        ");
        $secStmt->bind_param("ii", $newSectionId, $existing['company_id']);
        $secStmt->execute();
        $newSection = $secStmt->get_result()->fetch_assoc();
        $secStmt->close();

        if (!$newSection) {
            throw new Exception("Target section not found for this company.", 404);
        }
        if ($newSection['type'] !== 'general') {
            throw new Exception("Cannot move a general question to a KPI section.", 400);
        }

        $updateFields[] = "section_id = ?";
        $params[]       = $newSectionId;
        $types         .= "i";
    }

    // Always stamp updated_by
    $updateFields[] = "updated_by = ?";
    $params[]       = $loggedInUserId;
    $types         .= "i";

    if (empty($updateFields)) throw new Exception("No valid fields provided for update.", 400);

    $sql      = "UPDATE general_questions SET " . implode(", ", $updateFields) . " WHERE id = ?";
    $params[] = $questionId;
    $types   .= "i";

    $updateStmt = $conn->prepare($sql);
    if (!$updateStmt) throw new Exception("Database error: " . $conn->error, 500);
    $updateStmt->bind_param($types, ...$params);
    if (!$updateStmt->execute()) throw new Exception("Update failed: " . $updateStmt->error, 500);
    $updateStmt->close();

    // Log
    $logStmt = $conn->prepare("
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if ($logStmt) {
        $action      = "update_general_question";
        $targetTable = "general_questions";
        $description = "{$loggedInUserEmail} updated general question ID: {$questionId}";
        $logStmt->bind_param("iissis", $loggedInCompanyId, $loggedInUserId, $action, $targetTable, $questionId, $description);
        $logStmt->execute();
        $logStmt->close();
    }

    // Fetch updated
    $fetchStmt = $conn->prepare("
        SELECT gq.*, s.code AS section_code, s.label AS section_label,
               s.weight AS section_weight, s.type AS section_type,
               ac.year AS cycle_year, ac.title AS cycle_title
        FROM general_questions gq
        INNER JOIN appraisal_sections s ON s.id  = gq.section_id
        INNER JOIN appraisal_cycles ac  ON ac.id = s.cycle_id
        WHERE gq.id = ? LIMIT 1
    ");
    $fetchStmt->bind_param("i", $questionId);
    $fetchStmt->execute();
    $updated = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "General question updated successfully",
        "data"    => $updated
    ]);

} catch (Exception $e) {
    error_log("UpdateGeneralQuestion Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
