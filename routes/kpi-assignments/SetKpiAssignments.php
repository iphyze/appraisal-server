<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Bad Request: Only POST method is allowed", 400);
    }

    $userData          = requireRoles(['super_admin', 'admin', 'supervisor']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) throw new Exception("Invalid request format.", 400);

    foreach (['staff_user_id', 'section_id', 'question_ids'] as $field) {
        if (!isset($data[$field])) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    $staffUserId = (int) $data['staff_user_id'];
    $sectionId   = (int) $data['section_id'];
    $questionIds = $data['question_ids'];

    if (!is_array($questionIds)) {
        throw new Exception("'question_ids' must be an array. Pass an empty array [] to clear all assignments and revert to departmental default.", 400);
    }

    // Sanitise question IDs
    $questionIds = array_values(array_unique(array_filter(
        array_map('intval', $questionIds),
        fn($id) => $id > 0
    )));

    // Validate staff exists and belongs to same company
    $staffStmt = $conn->prepare("
        SELECT u.id, u.first_name, u.last_name, u.department, u.company_id
        FROM users u WHERE u.id = ? LIMIT 1
    ");
    $staffStmt->bind_param("i", $staffUserId);
    $staffStmt->execute();
    $staffMember = $staffStmt->get_result()->fetch_assoc();
    $staffStmt->close();

    if (!$staffMember) throw new Exception("Staff member not found.", 404);

    if (
        $loggedInUserRole !== 'super_admin' &&
        (int) $staffMember['company_id'] !== $loggedInCompanyId
    ) {
        throw new Exception("Unauthorized: Staff member does not belong to your company.", 403);
    }

    // Supervisor must have this staff assigned to them in this cycle
    if ($loggedInUserRole === 'supervisor') {
        $subStmt = $conn->prepare("
            SELECT sa.id
            FROM supervisor_assignments sa
            INNER JOIN appraisal_sections s ON s.cycle_id = sa.cycle_id
            WHERE sa.supervisor_id = ? AND sa.staff_id = ? AND s.id = ?
            LIMIT 1
        ");
        $subStmt->bind_param("iii", $loggedInUserId, $staffUserId, $sectionId);
        $subStmt->execute();
        if ($subStmt->get_result()->num_rows === 0) {
            throw new Exception("This staff member is not assigned to you in this cycle.", 403);
        }
        $subStmt->close();
    }

    // Validate section is type kpi and belongs to the company
    $sectionStmt = $conn->prepare("
        SELECT id, type, company_id FROM appraisal_sections
        WHERE id = ? AND company_id = ? LIMIT 1
    ");
    $sectionStmt->bind_param("ii", $sectionId, (int) $staffMember['company_id']);
    $sectionStmt->execute();
    $section = $sectionStmt->get_result()->fetch_assoc();
    $sectionStmt->close();

    if (!$section) throw new Exception("Section not found for this company.", 404);
    if ($section['type'] !== 'kpi') {
        throw new Exception("KPI assignments can only be set for sections of type 'kpi'.", 400);
    }

    /**
     * If question_ids is empty → clear all assignments for this staff + section.
     * This reverts them to the departmental default (fallback logic in GetKpiAssignments).
     */
    if (empty($questionIds)) {
        $clearStmt = $conn->prepare("
            DELETE FROM staff_kpi_assignments
            WHERE section_id = ? AND staff_user_id = ?
        ");
        $clearStmt->bind_param("ii", $sectionId, $staffUserId);
        $clearStmt->execute();
        $clearStmt->close();

        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => "KPI assignments cleared. Staff will now use the departmental default questions.",
            "data"    => [
                "staff_user_id" => $staffUserId,
                "section_id"    => $sectionId,
                "question_ids"  => [],
                "source"        => "departmental_default"
            ]
        ]);
        exit;
    }

    // Validate all provided question IDs belong to this section and company
    $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
    $validStmt    = $conn->prepare("
        SELECT id FROM kpi_questions
        WHERE id IN ({$placeholders})
          AND section_id  = ?
          AND company_id  = ?
          AND is_active   = 1
    ");
    $validTypes  = str_repeat('i', count($questionIds)) . "ii";
    $validParams = array_merge($questionIds, [$sectionId, (int) $staffMember['company_id']]);
    $validStmt->bind_param($validTypes, ...$validParams);
    $validStmt->execute();
    $validQuestions = $validStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $validStmt->close();

    $validIds    = array_column($validQuestions, 'id');
    $invalidIds  = array_diff($questionIds, $validIds);

    if (!empty($invalidIds)) {
        throw new Exception(
            "The following question IDs are invalid, inactive, or do not belong to this section: " .
            implode(', ', $invalidIds),
            400
        );
    }

    /**
     * Full replace strategy:
     * Delete all existing assignments for this staff + section,
     * then re-insert the new selection.
     * This makes it idempotent — call it multiple times with the same data safely.
     */
    $conn->begin_transaction();
    try {
        // Clear existing
        $deleteStmt = $conn->prepare("
            DELETE FROM staff_kpi_assignments
            WHERE section_id = ? AND staff_user_id = ?
        ");
        $deleteStmt->bind_param("ii", $sectionId, $staffUserId);
        $deleteStmt->execute();
        $deleteStmt->close();

        // Insert new selection
        $insertStmt = $conn->prepare("
            INSERT INTO staff_kpi_assignments (section_id, staff_user_id, kpi_question_id, assigned_by)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($validIds as $qId) {
            $insertStmt->bind_param("iiii", $sectionId, $staffUserId, $qId, $loggedInUserId);
            $insertStmt->execute();
        }
        $insertStmt->close();

        // Log
        $logStmt = $conn->prepare("
            INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if ($logStmt) {
            $action      = "set_kpi_assignments";
            $targetTable = "staff_kpi_assignments";
            $targetId    = $staffUserId;
            $staffName   = $staffMember['first_name'] . " " . $staffMember['last_name'];
            $description = "{$loggedInUserEmail} set " . count($validIds) .
                           " KPI question(s) for {$staffName} in section ID {$sectionId}";
            $logStmt->bind_param(
                "iissis",
                $loggedInCompanyId, $loggedInUserId,
                $action, $targetTable, $targetId,
                $description
            );
            $logStmt->execute();
            $logStmt->close();
        }

        $conn->commit();

        // Return the resolved question set
        $resolveStmt = $conn->prepare("
            SELECT
                kq.id,
                kq.question_text,
                kq.sort_order,
                kq.department,
                CASE
                    WHEN kq.staff_user_id IS NOT NULL THEN 'individual'
                    WHEN kq.supervisor_id IS NOT NULL THEN 'supervisor'
                    ELSE 'department'
                END AS scope
            FROM staff_kpi_assignments ska
            INNER JOIN kpi_questions kq ON kq.id = ska.kpi_question_id
            WHERE ska.section_id    = ?
              AND ska.staff_user_id = ?
            ORDER BY kq.sort_order ASC
        ");
        $resolveStmt->bind_param("ii", $sectionId, $staffUserId);
        $resolveStmt->execute();
        $assignedQuestions = $resolveStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $resolveStmt->close();

        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => count($validIds) . " KPI question(s) assigned successfully",
            "data"    => [
                "staff"        => [
                    "id"   => $staffMember['id'],
                    "name" => $staffMember['first_name'] . " " . $staffMember['last_name'],
                ],
                "section_id"   => $sectionId,
                "is_custom"    => true,
                "source"       => "custom_selection",
                "questions"    => $assignedQuestions,
                "count"        => count($assignedQuestions),
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("SetKpiAssignments Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}