<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Bad Request: Only PUT method is allowed", 400);
    }

    // Supervisors can update KPI questions scoped to them or their subordinates
    $userData          = requireRoles(['super_admin', 'admin', 'supervisor']);
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

    // Fetch existing question with its scope
    $checkStmt = $conn->prepare("
        SELECT kq.*, s.type AS section_type
        FROM kpi_questions kq
        INNER JOIN appraisal_sections s ON s.id = kq.section_id
        WHERE kq.id = ? LIMIT 1
    ");
    $checkStmt->bind_param("i", $questionId);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$existing) throw new Exception("KPI question not found.", 404);

    // Company check
    if (
        $loggedInUserRole !== 'super_admin' &&
        (int) $existing['company_id'] !== $loggedInCompanyId
    ) {
        throw new Exception("Unauthorized: You can only update questions within your company.", 403);
    }

    // ── Supervisor-specific restrictions ──────────────────────────────────────
    if ($loggedInUserRole === 'supervisor') {

        /**
         * A supervisor can only update a question if:
         *   a) It is scoped to themselves (supervisor_id = their ID)
         *   b) It is scoped to one of their own subordinates (staff_user_id is set
         *      and that staff is assigned to them)
         * They CANNOT update departmental default questions (those belong to admin).
         */
        if ($existing['supervisor_id'] === null && $existing['staff_user_id'] === null) {
            throw new Exception(
                "Supervisors cannot update departmental default KPI questions. " .
                "Contact an admin to update these.",
                403
            );
        }

        if (
            $existing['supervisor_id'] !== null &&
            (int) $existing['supervisor_id'] !== $loggedInUserId
        ) {
            throw new Exception(
                "You can only update KPI questions scoped to yourself.",
                403
            );
        }

        if ($existing['staff_user_id'] !== null) {
            // Verify this staff is their subordinate
            $subStmt = $conn->prepare("
                SELECT sa.id
                FROM supervisor_assignments sa
                INNER JOIN appraisal_sections s ON s.cycle_id = sa.cycle_id
                WHERE sa.supervisor_id = ?
                  AND sa.staff_id      = ?
                  AND s.id             = ?
                LIMIT 1
            ");
            $subStmt->bind_param("iii", $loggedInUserId, $existing['staff_user_id'], $existing['section_id']);
            $subStmt->execute();
            if ($subStmt->get_result()->num_rows === 0) {
                throw new Exception(
                    "You can only update questions for staff members assigned to you.",
                    403
                );
            }
            $subStmt->close();
        }
    }

    // ── Build update ──────────────────────────────────────────────────────────
    $updateFields = [];
    $params       = [];
    $types        = "";

    if (isset($data['question_text']) && trim($data['question_text']) !== '') {
        $updateFields[] = "question_text = ?";
        $params[]       = trim($data['question_text']);
        $types         .= "s";
    }

    if (isset($data['department']) && trim($data['department']) !== '') {
        // Supervisors cannot change the department a question belongs to
        if ($loggedInUserRole === 'supervisor') {
            throw new Exception("Supervisors cannot change the department of a KPI question.", 403);
        }
        $updateFields[] = "department = ?";
        $params[]       = trim($data['department']);
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

    // Supervisors cannot re-scope a question
    if ($loggedInUserRole !== 'supervisor') {

        if (array_key_exists('supervisor_id', $data)) {
            $updateFields[] = "supervisor_id = ?";
            $params[]       = $data['supervisor_id'] ? (int) $data['supervisor_id'] : null;
            $types         .= "i";
        }

        if (array_key_exists('staff_user_id', $data)) {
            $updateFields[] = "staff_user_id = ?";
            $params[]       = $data['staff_user_id'] ? (int) $data['staff_user_id'] : null;
            $types         .= "i";
        }
    }

    // Always stamp updated_by
    $updateFields[] = "updated_by = ?";
    $params[]       = $loggedInUserId;
    $types         .= "i";

    if (empty($updateFields)) throw new Exception("No valid fields provided for update.", 400);

    $sql      = "UPDATE kpi_questions SET " . implode(", ", $updateFields) . " WHERE id = ?";
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
        $action      = "update_kpi_question";
        $targetTable = "kpi_questions";
        $description = "{$loggedInUserEmail} updated KPI question ID: {$questionId}";
        $logStmt->bind_param("iissis", $loggedInCompanyId, $loggedInUserId, $action, $targetTable, $questionId, $description);
        $logStmt->execute();
        $logStmt->close();
    }

    // Fetch updated
    $fetchStmt = $conn->prepare("
        SELECT
            kq.*,
            s.code  AS section_code,
            s.label AS section_label,
            ac.year AS cycle_year,
            CONCAT(sup.first_name,' ',sup.last_name) AS supervisor_name,
            CONCAT(stf.first_name,' ',stf.last_name) AS staff_name,
            CASE
                WHEN kq.staff_user_id IS NOT NULL THEN 'individual'
                WHEN kq.supervisor_id IS NOT NULL THEN 'supervisor'
                ELSE 'department'
            END AS scope
        FROM kpi_questions kq
        INNER JOIN appraisal_sections s ON s.id  = kq.section_id
        INNER JOIN appraisal_cycles ac  ON ac.id = s.cycle_id
        LEFT  JOIN users sup ON sup.id = kq.supervisor_id
        LEFT  JOIN users stf ON stf.id = kq.staff_user_id
        WHERE kq.id = ? LIMIT 1
    ");
    $fetchStmt->bind_param("i", $questionId);
    $fetchStmt->execute();
    $updated = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "KPI question updated successfully",
        "data"    => $updated
    ]);

} catch (Exception $e) {
    error_log("UpdateKpiQuestion Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}