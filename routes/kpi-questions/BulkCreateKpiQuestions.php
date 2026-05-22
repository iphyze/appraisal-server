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
        SELECT s.id, s.company_id, s.type, s.code, s.label, s.cycle_id, ac.year AS cycle_year
        FROM appraisal_sections s
        INNER JOIN appraisal_cycles ac ON ac.id = s.cycle_id
        WHERE s.id = ?
        LIMIT 1
    ");
    if (!$sectionStmt) throw new Exception("Database error: " . $conn->error, 500);
    $sectionStmt->bind_param("i", $sectionId);
    $sectionStmt->execute();
    $section = $sectionStmt->get_result()->fetch_assoc();
    $sectionStmt->close();

    if (!$section) throw new Exception("Section not found.", 404);
    if ($section['type'] !== 'kpi') throw new Exception("Selected section is not a KPI section.", 400);

    $companyId = (int) $section['company_id'];
    $cycleId   = (int) $section['cycle_id'];

    if ($loggedInUserRole !== 'super_admin' && $companyId !== $loggedInCompanyId) {
        throw new Exception("Unauthorized: You can only create KPI questions within your company.", 403);
    }

    if ($loggedInUserRole === 'supervisor') {
        if (!$supervisorId && !$staffUserId) {
            $supervisorId = $loggedInUserId;
        }

        if ($supervisorId && $supervisorId !== $loggedInUserId) {
            throw new Exception("You can only create KPI questions scoped to yourself.", 403);
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
                throw new Exception("You can only create KPI questions for staff assigned to you.", 403);
            }
            $subStmt->close();
        }
    }

    $cleanQuestions = [];
    foreach ($data['questions'] as $index => $row) {
        if (!is_array($row)) {
            throw new Exception("Question row " . ($index + 1) . " is invalid.", 400);
        }

        $questionText = isset($row['question_text']) ? trim($row['question_text']) : '';
        if ($questionText === '') {
            throw new Exception("Question text is required on row " . ($index + 1) . ".", 400);
        }

        $cleanQuestions[] = [
            'question_text' => $questionText,
            'sort_order'   => isset($row['sort_order']) ? (int) $row['sort_order'] : $index,
        ];
    }

    $conn->begin_transaction();

    $insertStmt = $conn->prepare("
        INSERT INTO kpi_questions
            (company_id, section_id, department, supervisor_id, staff_user_id, question_text, sort_order, is_active, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$insertStmt) throw new Exception("Database error: " . $conn->error, 500);

    $createdIds = [];
    foreach ($cleanQuestions as $row) {
        $questionText = $row['question_text'];
        $sortOrder    = $row['sort_order'];

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
            throw new Exception("Failed to create KPI question: " . $insertStmt->error, 500);
        }

        $createdIds[] = $insertStmt->insert_id;
    }
    $insertStmt->close();

    $logStmt = $conn->prepare("
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if ($logStmt) {
        $action      = "bulk_create_kpi_questions";
        $targetTable = "kpi_questions";
        $targetId    = $createdIds[0] ?? 0;
        $count       = count($createdIds);
        $description = "{$loggedInUserEmail} created {$count} KPI question(s) for {$department}.";
        $logStmt->bind_param("iissis", $companyId, $loggedInUserId, $action, $targetTable, $targetId, $description);
        $logStmt->execute();
        $logStmt->close();
    }

    $conn->commit();

    $placeholders = implode(',', array_fill(0, count($createdIds), '?'));
    $types = str_repeat('i', count($createdIds));
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
    $fetchStmt->bind_param($types, ...$createdIds);
    $fetchStmt->execute();
    $created = $fetchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $fetchStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => count($createdIds) . " KPI question(s) created successfully",
        "data"    => $created
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn instanceof mysqli) $conn->rollback();
    error_log("BulkCreateKpiQuestions Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
