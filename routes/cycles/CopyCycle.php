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

    foreach (['from_cycle_id', 'to_cycle_id'] as $field) {
        if (!isset($data[$field]) || !is_numeric($data[$field])) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    $fromCycleId = (int) $data['from_cycle_id'];
    $toCycleId   = (int) $data['to_cycle_id'];

    // What to copy — defaults to copying everything
    $copySections         = isset($data['copy_sections'])          ? (bool) $data['copy_sections']          : true;
    $copyKpiQuestions     = isset($data['copy_kpi_questions'])     ? (bool) $data['copy_kpi_questions']     : true;
    $copyGeneralQuestions = isset($data['copy_general_questions']) ? (bool) $data['copy_general_questions'] : true;

    if ($fromCycleId === $toCycleId) {
        throw new Exception("'from_cycle_id' and 'to_cycle_id' cannot be the same.", 400);
    }

    // Validate source cycle
    $fromStmt = $conn->prepare("
        SELECT id, company_id, year, title FROM appraisal_cycles
        WHERE id = ? LIMIT 1
    ");
    $fromStmt->bind_param("i", $fromCycleId);
    $fromStmt->execute();
    $fromCycle = $fromStmt->get_result()->fetch_assoc();
    $fromStmt->close();

    if (!$fromCycle) throw new Exception("Source cycle not found.", 404);

    if (
        $loggedInUserRole !== 'super_admin' &&
        (int) $fromCycle['company_id'] !== $loggedInCompanyId
    ) {
        throw new Exception("Unauthorized: Source cycle does not belong to your company.", 403);
    }

    // Validate target cycle
    $toStmt = $conn->prepare("
        SELECT id, company_id, year, title FROM appraisal_cycles
        WHERE id = ? LIMIT 1
    ");
    $toStmt->bind_param("i", $toCycleId);
    $toStmt->execute();
    $toCycle = $toStmt->get_result()->fetch_assoc();
    $toStmt->close();

    if (!$toCycle) throw new Exception("Target cycle not found.", 404);

    if (
        $loggedInUserRole !== 'super_admin' &&
        (int) $toCycle['company_id'] !== $loggedInCompanyId
    ) {
        throw new Exception("Unauthorized: Target cycle does not belong to your company.", 403);
    }

    // Fetch source sections
    $sectionsStmt = $conn->prepare("
        SELECT * FROM appraisal_sections
        WHERE cycle_id = ? AND company_id = ?
        ORDER BY sort_order ASC
    ");
    $sectionsStmt->bind_param("ii", $fromCycleId, $fromCycle['company_id']);
    $sectionsStmt->execute();
    $sourceSections = $sectionsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sectionsStmt->close();

    if (empty($sourceSections)) {
        throw new Exception("Source cycle has no sections to copy.", 400);
    }

    $conn->begin_transaction();
    try {
        $stats = [
            'sections_copied'          => 0,
            'sections_skipped'         => 0,
            'kpi_questions_copied'     => 0,
            'general_questions_copied' => 0,
        ];

        // Map: old section_id → new section_id
        $sectionIdMap = [];

        foreach ($sourceSections as $section) {
            // Check if a section with same code already exists in target cycle
            $existsStmt = $conn->prepare("
                SELECT id FROM appraisal_sections
                WHERE cycle_id = ? AND company_id = ? AND code = ?
                LIMIT 1
            ");
            $existsStmt->bind_param("iis", $toCycleId, $toCycle['company_id'], $section['code']);
            $existsStmt->execute();
            $existingSection = $existsStmt->get_result()->fetch_assoc();
            $existsStmt->close();

            if ($existingSection) {
                // Section already exists — map to existing and skip
                $sectionIdMap[$section['id']] = $existingSection['id'];
                $stats['sections_skipped']++;
                continue;
            }

            if (!$copySections) continue;

            // Insert new section into target cycle
            $insertSectionStmt = $conn->prepare("
                INSERT INTO appraisal_sections
                  (company_id, cycle_id, code, label, description, type, weight, sort_order, is_active, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insertSectionStmt->bind_param(
                "iissssdiii",
                $toCycle['company_id'], $toCycleId,
                $section['code'], $section['label'], $section['description'],
                $section['type'], $section['weight'],
                $section['sort_order'], $section['is_active'],
                $loggedInUserId
            );
            $insertSectionStmt->execute();
            $newSectionId                 = $insertSectionStmt->insert_id;
            $sectionIdMap[$section['id']] = $newSectionId;
            $insertSectionStmt->close();
            $stats['sections_copied']++;

            // Copy KPI questions for this section
            if ($copyKpiQuestions && $section['type'] === 'kpi') {
                $kpiStmt = $conn->prepare("
                    SELECT * FROM kpi_questions
                    WHERE section_id = ? AND is_active = 1
                    ORDER BY sort_order ASC
                ");
                $kpiStmt->bind_param("i", $section['id']);
                $kpiStmt->execute();
                $kpiQuestions = $kpiStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $kpiStmt->close();

                foreach ($kpiQuestions as $kq) {
                    $insertKpiStmt = $conn->prepare("
                        INSERT INTO kpi_questions
                          (company_id, section_id, department, supervisor_id, staff_user_id,
                           question_text, sort_order, is_active, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insertKpiStmt->bind_param(
                        "iisiisiii",
                        $toCycle['company_id'], $newSectionId,
                        $kq['department'], $kq['supervisor_id'], $kq['staff_user_id'],
                        $kq['question_text'], $kq['sort_order'],
                        $kq['is_active'], $loggedInUserId
                    );
                    $insertKpiStmt->execute();
                    $insertKpiStmt->close();
                    $stats['kpi_questions_copied']++;
                }
            }

            // Copy general questions for this section
            if ($copyGeneralQuestions && $section['type'] === 'general') {
                $genStmt = $conn->prepare("
                    SELECT * FROM general_questions
                    WHERE section_id = ? AND is_active = 1
                    ORDER BY sort_order ASC
                ");
                $genStmt->bind_param("i", $section['id']);
                $genStmt->execute();
                $genQuestions = $genStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $genStmt->close();

                foreach ($genQuestions as $gq) {
                    $insertGenStmt = $conn->prepare("
                        INSERT INTO general_questions
                          (company_id, section_id, question_text, sort_order, is_active, created_by)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $insertGenStmt->bind_param(
                        "iisiii",
                        $toCycle['company_id'], $newSectionId,
                        $gq['question_text'], $gq['sort_order'],
                        $gq['is_active'], $loggedInUserId
                    );
                    $insertGenStmt->execute();
                    $insertGenStmt->close();
                    $stats['general_questions_copied']++;
                }
            }
        }

        // Log
        $logStmt = $conn->prepare("
            INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if ($logStmt) {
            $action      = "copy_cycle";
            $targetTable = "appraisal_cycles";
            $description = "{$loggedInUserEmail} copied sections & questions from cycle " .
                           "{$fromCycle['year']} (ID:{$fromCycleId}) to cycle " .
                           "{$toCycle['year']} (ID:{$toCycleId}). " .
                           "Sections: {$stats['sections_copied']} copied, {$stats['sections_skipped']} skipped. " .
                           "KPI Qs: {$stats['kpi_questions_copied']}. " .
                           "General Qs: {$stats['general_questions_copied']}.";
            $logStmt->bind_param(
                "iissis",
                $loggedInCompanyId, $loggedInUserId,
                $action, $targetTable, $toCycleId,
                $description
            );
            $logStmt->execute();
            $logStmt->close();
        }

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => "Cycle content copied successfully",
            "data"    => [
                "from_cycle" => ["id" => $fromCycleId, "year" => $fromCycle['year']],
                "to_cycle"   => ["id" => $toCycleId,   "year" => $toCycle['year']],
                "stats"      => $stats,
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("CopyCycle Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}