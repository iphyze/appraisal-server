<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Bad Request: Only DELETE method is allowed", 400);
    }

    $userData          = requireRoles(['super_admin', 'admin']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) throw new Exception("Invalid request format.", 400);

    if (!isset($data['ids']) || !is_array($data['ids']) || count($data['ids']) === 0) {
        throw new Exception("Field 'ids' is required and must be a non-empty array.", 400);
    }

    $targetIds = array_values(array_unique(array_filter(
        array_map('intval', $data['ids']),
        fn($id) => $id > 0
    )));

    if (empty($targetIds)) throw new Exception("No valid section IDs provided.", 400);

    // Fetch sections
    $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
    $checkStmt    = $conn->prepare("
        SELECT id, company_id, code, label, cycle_id
        FROM appraisal_sections WHERE id IN ({$placeholders})
    ");
    $checkStmt->bind_param(str_repeat('i', count($targetIds)), ...$targetIds);
    $checkStmt->execute();
    $foundSections = $checkStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $checkStmt->close();

    if (count($foundSections) === 0) throw new Exception("No matching sections found.", 404);

    foreach ($foundSections as $section) {
        if (
            $loggedInUserRole !== 'super_admin' &&
            (int) $section['company_id'] !== $loggedInCompanyId
        ) {
            throw new Exception(
                "Unauthorized: Section ID {$section['id']} does not belong to your company.", 403
            );
        }

        // Cannot delete a section that has responses (appraisals already submitted)
        $responseCheckStmt = $conn->prepare("
            SELECT COUNT(*) AS cnt FROM appraisal_section_scores WHERE section_id = ?
        ");
        $responseCheckStmt->bind_param("i", $section['id']);
        $responseCheckStmt->execute();
        $cnt = (int) $responseCheckStmt->get_result()->fetch_assoc()['cnt'];
        $responseCheckStmt->close();

        if ($cnt > 0) {
            throw new Exception(
                "Cannot delete section '{$section['code']} - {$section['label']}' " .
                "because it has {$cnt} appraisal response(s) linked to it. " .
                "Deactivate it instead.",
                400
            );
        }
    }

    $validIds = array_column($foundSections, 'id');

    $conn->begin_transaction();
    try {
        $delPlaceholders = implode(',', array_fill(0, count($validIds), '?'));
        $deleteStmt      = $conn->prepare("DELETE FROM appraisal_sections WHERE id IN ({$delPlaceholders})");
        $deleteStmt->bind_param(str_repeat('i', count($validIds)), ...$validIds);
        if (!$deleteStmt->execute()) throw new Exception("Delete failed: " . $deleteStmt->error, 500);
        $deletedCount = $deleteStmt->affected_rows;
        $deleteStmt->close();

        // Log one entry per section
        $logStmt = $conn->prepare("
            INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($foundSections as $s) {
            $action      = "delete_section";
            $targetTable = "appraisal_sections";
            $targetId    = (int) $s['id'];
            $description = "{$loggedInUserEmail} deleted section {$s['code']}: {$s['label']}";
            $logStmt->bind_param("iissis", $loggedInCompanyId, $loggedInUserId, $action, $targetTable, $targetId, $description);
            $logStmt->execute();
        }
        $logStmt->close();

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => "{$deletedCount} section(s) deleted successfully.",
            "data"    => ["deleted_count" => $deletedCount, "deleted_ids" => $validIds]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("DeleteSections Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
