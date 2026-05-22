<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Bad Request: Only POST method is allowed", 400);
    }

    // Supervisors can also create KPI questions for their subordinates
    $userData          = requireRoles(['super_admin', 'admin', 'supervisor']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) throw new Exception("Invalid request format.", 400);

    // Required fields
    foreach (['section_id', 'department', 'question_text'] as $field) {
        if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    $sectionId    = (int) $data['section_id'];
    $department   = trim($data['department']);
    $questionText = trim($data['question_text']);
    $sortOrder    = isset($data['sort_order']) ? (int) $data['sort_order'] : 0;
    $isActive     = isset($data['is_active'])  ? (int) $data['is_active']  : 1;

    // Optional scope fields
    $supervisorId = isset($data['supervisor_id']) && $data['supervisor_id']
                    ? (int) $data['supervisor_id'] : null;
    $staffUserId  = isset($data['staff_user_id']) && $data['staff_user_id']
                    ? (int) $data['staff_user_id'] : null;

    // company_id — supervisor always locked to their own company
    $companyId = ($loggedInUserRole === 'super_admin' && isset($data['company_id']))
        ? (int) $data['company_id']
        : $loggedInCompanyId;

    // ── Supervisor-specific restrictions ──────────────────────────────────────
    if ($loggedInUserRole === 'supervisor') {

        /**
         * Supervisors can only create questions that are:
         *   a) Scoped to themselves (supervisor_id = their own ID)
         *   b) Scoped to one of their own subordinates (staff_user_id set)
         * They cannot create departmental default questions (both NULL)
         * and cannot create questions scoped to another supervisor.
         */
        if ($supervisorId === null && $staffUserId === null) {
            throw new Exception(
                "Supervisors cannot create departmental default KPI questions. " .
                "Please scope the question to yourself (supervisor_id) or a specific staff member (staff_user_id).",
                403
            );
        }

        if ($supervisorId !== null && $supervisorId !== $loggedInUserId) {
            throw new Exception(
                "Supervisors can only create questions scoped to themselves, not another supervisor.",
                403
            );
        }

        // If scoping to a staff member, verify the staff is their subordinate
        if ($staffUserId !== null) {
            $subStmt = $conn->prepare("
                SELECT sa.id
                FROM supervisor_assignments sa
                INNER JOIN appraisal_sections s ON s.cycle_id = sa.cycle_id
                WHERE sa.supervisor_id = ?
                  AND sa.staff_id      = ?
                  AND s.id             = ?
                LIMIT 1
            ");
            $subStmt->bind_param("iii", $loggedInUserId, $staffUserId, $sectionId);
            $subStmt->execute();
            if ($subStmt->get_result()->num_rows === 0) {
                throw new Exception(
                    "You can only create questions for staff members assigned to you in this cycle.",
                    403
                );
            }
            $subStmt->close();
        }

        // Force supervisor_id to themselves if not scoping to a specific staff
        if ($staffUserId === null) {
            $supervisorId = $loggedInUserId;
        }
    }

    // ── Validate section ──────────────────────────────────────────────────────
    $sectionStmt = $conn->prepare("
        SELECT id, type, company_id FROM appraisal_sections
        WHERE id = ? AND company_id = ?
        LIMIT 1
    ");
    $sectionStmt->bind_param("ii", $sectionId, $companyId);
    $sectionStmt->execute();
    $section = $sectionStmt->get_result()->fetch_assoc();
    $sectionStmt->close();

    if (!$section) throw new Exception("Section not found for this company.", 404);
    if ($section['type'] !== 'kpi') {
        throw new Exception("KPI questions can only be added to a section of type 'kpi'.", 400);
    }

    // ── Validate supervisor belongs to same company if provided ───────────────
    if ($supervisorId && $loggedInUserRole !== 'supervisor') {
        $supStmt = $conn->prepare("
            SELECT id FROM users
            WHERE id = ? AND company_id = ? AND role_id = 3
            LIMIT 1
        ");
        $supStmt->bind_param("ii", $supervisorId, $companyId);
        $supStmt->execute();
        if ($supStmt->get_result()->num_rows === 0) {
            throw new Exception("Supervisor not found or does not belong to this company.", 404);
        }
        $supStmt->close();
    }

    // ── Validate staff belongs to same company if provided ────────────────────
    if ($staffUserId) {
        $staffStmt = $conn->prepare("
            SELECT id FROM users WHERE id = ? AND company_id = ? LIMIT 1
        ");
        $staffStmt->bind_param("ii", $staffUserId, $companyId);
        $staffStmt->execute();
        if ($staffStmt->get_result()->num_rows === 0) {
            throw new Exception("Staff member not found or does not belong to this company.", 404);
        }
        $staffStmt->close();
    }

    // ── Insert ────────────────────────────────────────────────────────────────
    $insertStmt = $conn->prepare("
        INSERT INTO kpi_questions
          (company_id, section_id, department, supervisor_id, staff_user_id,
           question_text, sort_order, is_active, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$insertStmt) throw new Exception("Database error: " . $conn->error, 500);

    $insertStmt->bind_param(
        "iisiisiii",
        $companyId, $sectionId, $department,
        $supervisorId, $staffUserId,
        $questionText, $sortOrder, $isActive, $loggedInUserId
    );
    if (!$insertStmt->execute()) {
        throw new Exception("Failed to create KPI question: " . $insertStmt->error, 500);
    }

    $newId = $insertStmt->insert_id;
    $insertStmt->close();

    // Scope label for log
    $scope = $staffUserId ? 'individual' : ($supervisorId ? 'supervisor' : 'department');

    // Log
    $logStmt = $conn->prepare("
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if ($logStmt) {
        $action      = "create_kpi_question";
        $targetTable = "kpi_questions";
        $description = "{$loggedInUserEmail} created KPI question (scope: {$scope}, dept: {$department})";
        $logStmt->bind_param("iissis", $companyId, $loggedInUserId, $action, $targetTable, $newId, $description);
        $logStmt->execute();
        $logStmt->close();
    }

    // Fetch created record
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
    $fetchStmt->bind_param("i", $newId);
    $fetchStmt->execute();
    $newQuestion = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "KPI question created successfully",
        "data"    => $newQuestion
    ]);

} catch (Exception $e) {
    error_log("CreateKpiQuestion Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}