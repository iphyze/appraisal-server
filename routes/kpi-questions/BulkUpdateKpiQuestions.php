<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

function nullableInt($value) {
    if ($value === null || $value === '' || !is_numeric($value)) return null;
    return (int) $value;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Bad Request: Only PUT method is allowed", 400);
    }

    $userData          = requireRoles(['super_admin', 'admin', 'supervisor']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) throw new Exception("Invalid request format.", 400);

    if (!isset($data['section_id']) || !is_numeric($data['section_id'])) {
        throw new Exception("Field 'section_id' is required.", 400);
    }

    if (!isset($data['department']) || trim($data['department']) === '') {
        throw new Exception("Field 'department' is required.", 400);
    }

    if (!isset($data['questions']) || !is_array($data['questions']) || count($data['questions']) === 0) {
        throw new Exception("Field 'questions' must be a non-empty array.", 400);
    }

    $sectionId    = (int) $data['section_id'];
    $department   = trim($data['department']);
    $isActive     = isset($data['is_active']) ? (int) $data['is_active'] : 1;
    $supervisorId = nullableInt($data['supervisor_id'] ?? null);
    $staffUserId  = nullableInt($data['staff_user_id'] ?? null);

    $sectionStmt = $conn->prepare("
        SELECT id, company_id, type, code, label, cycle_id
        FROM appraisal_sections
        WHERE id = ?
        LIMIT 1
    ");
    $sectionStmt->bind_param("i", $sectionId);
    $sectionStmt->execute();
    $section = $sectionStmt->get_result()->fetch_assoc();
    $sectionStmt->close();

    if (!$section) throw new Exception("Section not found.", 404);
    if ($section['type'] !== 'kpi') throw new Exception("Selected section is not a KPI section.", 400);

    $companyId = (int) $section['company_id'];
    $cycleId   = (int) $section['cycle_id'];

    if ($loggedInUserRole !== 'super_admin' && $companyId !== $loggedInCompanyId) {
        throw new Exception("Unauthorized: You can only update KPI questions within your company.", 403);
    }

    if ($loggedInUserRole === 'supervisor') {
        if (!$supervisorId && !$staffUserId) {
            $supervisorId = $loggedInUserId;
        }

        if ($supervisorId && $supervisorId !== $loggedInUserId) {
            throw new Exception("You can only save KPI questions scoped to yourself.", 403);
        }

        if ($staffUserId) {
            $subStmt = $conn->prepare("
                SELECT id
                FROM supervisor_assignments
                WHERE supervisor_id = ?
                  AND staff_id = ?
                  AND cycle_id = ?
                LIMIT 1
            ");
            $subStmt->bind_param("iii", $loggedInUserId, $staffUserId, $cycleId);
            $subStmt->execute();
            if ($subStmt->get_result()->num_rows === 0) {
                throw new Exception("You can only save KPI questions for staff assigned to you.", 403);
            }
            $subStmt->close();
        }
    }

    $cleanQuestions = [];
    foreach ($data['questions'] as $index => $row) {
        if (!is_array($row)) throw new Exception("Question row " . ($index + 1) . " is invalid.", 400);

        $questionText = isset($row['question_text']) ? trim($row['question_text']) : '';
        if ($questionText === '') throw new Exception("Question text is required on row " . ($index + 1) . ".", 400);

        $cleanQuestions[] = [
            'id'            => isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null,
            'question_text' => $questionText,
            'sort_order'   => isset($row['sort_order']) ? (int) $row['sort_order'] : $index,
        ];
    }

    $conn->begin_transaction();

    $updateStmt = $conn->prepare("
        UPDATE kpi_questions
        SET section_id = ?, department = ?, supervisor_id = ?, staff_user_id = ?,
            question_text = ?, sort_order = ?, is_active = ?, updated_by = ?
        WHERE id = ? AND company_id = ?
    ");
    if (!$updateStmt) throw new Exception("Database error: " . $conn->error, 500);

    $insertStmt = $conn->prepare("
        INSERT INTO kpi_questions
            (company_id, section_id, department, supervisor_id, staff_user_id, question_text, sort_order, is_active, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$insertStmt) throw new Exception("Database error: " . $conn->error, 500);

    $affectedIds = [];
    foreach ($cleanQuestions as $row) {
        $questionText = $row['question_text'];
        $sortOrder    = $row['sort_order'];

        if ($row['id']) {
            $id = $row['id'];

            $checkStmt = $conn->prepare("SELECT id, supervisor_id, staff_user_id FROM kpi_questions WHERE id = ? AND company_id = ? LIMIT 1");
            $checkStmt->bind_param("ii", $id, $companyId);
            $checkStmt->execute();
            $existing = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();

            if (!$existing) throw new Exception("KPI question ID {$id} was not found for this company.", 404);

            if ($loggedInUserRole === 'supervisor') {
                $existingSupervisorId = $existing['supervisor_id'] !== null ? (int) $existing['supervisor_id'] : null;
                $existingStaffId      = $existing['staff_user_id'] !== null ? (int) $existing['staff_user_id'] : null;

                if (!$existingSupervisorId && !$existingStaffId) {
                    throw new Exception("Supervisors cannot update departmental default KPI questions.", 403);
                }

                if ($existingSupervisorId && $existingSupervisorId !== $loggedInUserId) {
                    throw new Exception("You can only update KPI questions scoped to yourself.", 403);
                }
            }

            $updateStmt->bind_param(
                "isiisiiiii",
                $sectionId,
                $department,
                $supervisorId,
                $staffUserId,
                $questionText,
                $sortOrder,
                $isActive,
                $loggedInUserId,
                $id,
                $companyId
            );
            if (!$updateStmt->execute()) {
                throw new Exception("Failed to update KPI question: " . $updateStmt->error, 500);
            }
            $affectedIds[] = $id;
        } else {
            $insertStmt->bind_param(
                "iisiisiii",
                $companyId,
                $sectionId,
                $department,
                $supervisorId,
                $staffUserId,
                $questionText,
                $sortOrder,
                $isActive,
                $loggedInUserId
            );
            if (!$insertStmt->execute()) {
                throw new Exception("Failed to create new KPI question: " . $insertStmt->error, 500);
            }
            $affectedIds[] = $insertStmt->insert_id;
        }
    }

    $updateStmt->close();
    $insertStmt->close();

    $logStmt = $conn->prepare("
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if ($logStmt) {
        $action      = "bulk_update_kpi_questions";
        $targetTable = "kpi_questions";
        $targetId    = $affectedIds[0] ?? 0;
        $count       = count($affectedIds);
        $description = "{$loggedInUserEmail} saved {$count} KPI question row(s) for {$department}.";
        $logStmt->bind_param("iissis", $companyId, $loggedInUserId, $action, $targetTable, $targetId, $description);
        $logStmt->execute();
        $logStmt->close();
    }

    $conn->commit();

    $placeholders = implode(',', array_fill(0, count($affectedIds), '?'));
    $types = str_repeat('i', count($affectedIds));
    $fetchStmt = $conn->prepare("
        SELECT
            kq.*,
            s.code AS section_code,
            s.label AS section_label,
            ac.year AS cycle_year,
            CONCAT(sup.first_name, ' ', sup.last_name) AS supervisor_name,
            CONCAT(stf.first_name, ' ', stf.last_name) AS staff_name
        FROM kpi_questions kq
        INNER JOIN appraisal_sections s ON s.id = kq.section_id
        INNER JOIN appraisal_cycles ac ON ac.id = s.cycle_id
        LEFT JOIN users sup ON sup.id = kq.supervisor_id
        LEFT JOIN users stf ON stf.id = kq.staff_user_id
        WHERE kq.id IN ($placeholders)
        ORDER BY kq.sort_order ASC, kq.id ASC
    ");
    $fetchStmt->bind_param($types, ...$affectedIds);
    $fetchStmt->execute();
    $saved = $fetchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $fetchStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => count($affectedIds) . " KPI question row(s) saved successfully",
        "data"    => $saved
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn instanceof mysqli) $conn->rollback();
    error_log("BulkUpdateKpiQuestions Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
