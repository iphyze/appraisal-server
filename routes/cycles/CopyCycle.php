<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Bad Request: Only POST method is allowed.', 405);
    }

    $userData          = requireRoles(['super_admin', 'admin']);
    $loggedInUserId    = (int) ($userData['id'] ?? 0);
    $loggedInUserEmail = trim((string) ($userData['email'] ?? 'System user'));
    $loggedInUserRole  = authRoleKey($userData['role'] ?? '');
    $loggedInCompanyId = (int) ($userData['company_id'] ?? 0);

    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid request format. Expected a JSON object.', 400);
    }

    foreach (['from_cycle_id', 'to_cycle_id'] as $field) {
        if (!isset($data[$field]) || !is_numeric($data[$field])) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    $fromCycleId = (int) $data['from_cycle_id'];
    $toCycleId   = (int) $data['to_cycle_id'];

    $copySections         = array_key_exists('copy_sections', $data) ? (bool) $data['copy_sections'] : true;
    $copyKpiQuestions     = array_key_exists('copy_kpi_questions', $data) ? (bool) $data['copy_kpi_questions'] : true;
    $copyGeneralQuestions = array_key_exists('copy_general_questions', $data) ? (bool) $data['copy_general_questions'] : true;

    if ($fromCycleId <= 0 || $toCycleId <= 0) {
        throw new Exception('Please select valid source and target cycles.', 400);
    }

    if ($fromCycleId === $toCycleId) {
        throw new Exception('The source and target cycles cannot be the same.', 400);
    }

    $cycleStmt = $conn->prepare('
        SELECT id, company_id, year, title
        FROM appraisal_cycles
        WHERE id IN (?, ?)
        ORDER BY id ASC
    ');
    if (!$cycleStmt) {
        throw new Exception('Database error: ' . $conn->error, 500);
    }
    $cycleStmt->bind_param('ii', $fromCycleId, $toCycleId);
    $cycleStmt->execute();
    $cycleRows = $cycleStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $cycleStmt->close();

    $cyclesById = [];
    foreach ($cycleRows as $cycleRow) {
        $cyclesById[(int) $cycleRow['id']] = $cycleRow;
    }

    $fromCycle = $cyclesById[$fromCycleId] ?? null;
    $toCycle   = $cyclesById[$toCycleId] ?? null;

    if (!$fromCycle) {
        throw new Exception('Source cycle not found.', 404);
    }
    if (!$toCycle) {
        throw new Exception('Target cycle not found.', 404);
    }

    $fromCompanyId = (int) $fromCycle['company_id'];
    $toCompanyId   = (int) $toCycle['company_id'];

    if ($loggedInUserRole !== 'super_admin') {
        if ($fromCompanyId !== $loggedInCompanyId || $toCompanyId !== $loggedInCompanyId) {
            throw new Exception('Unauthorized: You can only copy cycles within your company.', 403);
        }
    }

    // Questions may contain company-specific departments and user references, so cross-company copies are unsafe.
    if ($fromCompanyId !== $toCompanyId) {
        throw new Exception('Cycle setup can only be copied between cycles belonging to the same company.', 409);
    }

    $sectionsStmt = $conn->prepare('
        SELECT *
        FROM appraisal_sections
        WHERE cycle_id = ? AND company_id = ?
        ORDER BY sort_order ASC, id ASC
    ');
    if (!$sectionsStmt) {
        throw new Exception('Database error: ' . $conn->error, 500);
    }
    $sectionsStmt->bind_param('ii', $fromCycleId, $fromCompanyId);
    $sectionsStmt->execute();
    $sourceSections = $sectionsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sectionsStmt->close();

    if (!$sourceSections) {
        throw new Exception('Source cycle has no sections to copy.', 400);
    }

    $conn->begin_transaction();

    try {
        $stats = [
            'sections_copied'           => 0,
            'sections_reused'           => 0,
            'sections_skipped'          => 0,
            'kpi_questions_copied'      => 0,
            'kpi_questions_skipped'     => 0,
            'general_questions_copied'  => 0,
            'general_questions_skipped' => 0,
        ];

        foreach ($sourceSections as $section) {
            $sourceSectionId = (int) $section['id'];
            $targetSectionId = 0;

            $existsStmt = $conn->prepare('
                SELECT id, type
                FROM appraisal_sections
                WHERE cycle_id = ? AND company_id = ? AND code = ?
                LIMIT 1
            ');
            if (!$existsStmt) {
                throw new Exception('Database error: ' . $conn->error, 500);
            }
            $existsStmt->bind_param('iis', $toCycleId, $toCompanyId, $section['code']);
            $existsStmt->execute();
            $existingSection = $existsStmt->get_result()->fetch_assoc();
            $existsStmt->close();

            if ($existingSection) {
                if ((string) $existingSection['type'] !== (string) $section['type']) {
                    throw new Exception(
                        'Target section ' . $section['code'] . ' has a different section type. Update or remove it before copying this setup.',
                        409
                    );
                }
                $targetSectionId = (int) $existingSection['id'];
                $stats['sections_reused']++;
            } elseif ($copySections) {
                $insertSectionStmt = $conn->prepare('
                    INSERT INTO appraisal_sections
                        (company_id, cycle_id, code, label, description, type, weight, sort_order, is_active, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                if (!$insertSectionStmt) {
                    throw new Exception('Database error: ' . $conn->error, 500);
                }
                $insertSectionStmt->bind_param(
                    'iissssdiii',
                    $toCompanyId,
                    $toCycleId,
                    $section['code'],
                    $section['label'],
                    $section['description'],
                    $section['type'],
                    $section['weight'],
                    $section['sort_order'],
                    $section['is_active'],
                    $loggedInUserId
                );
                if (!$insertSectionStmt->execute()) {
                    throw new Exception('Unable to copy section ' . $section['code'] . ': ' . $insertSectionStmt->error, 500);
                }
                $targetSectionId = (int) $insertSectionStmt->insert_id;
                $insertSectionStmt->close();
                $stats['sections_copied']++;
            } else {
                $stats['sections_skipped']++;
                continue;
            }

            // Copy into both newly created and already existing target sections.
            if ($copyKpiQuestions && $section['type'] === 'kpi') {
                $kpiStmt = $conn->prepare('
                    SELECT *
                    FROM kpi_questions
                    WHERE section_id = ?
                    ORDER BY sort_order ASC, id ASC
                ');
                if (!$kpiStmt) {
                    throw new Exception('Database error: ' . $conn->error, 500);
                }
                $kpiStmt->bind_param('i', $sourceSectionId);
                $kpiStmt->execute();
                $kpiQuestions = $kpiStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $kpiStmt->close();

                foreach ($kpiQuestions as $kq) {
                    $duplicateStmt = $conn->prepare('
                        SELECT id
                        FROM kpi_questions
                        WHERE company_id = ?
                          AND section_id = ?
                          AND department = ?
                          AND supervisor_id <=> ?
                          AND staff_user_id <=> ?
                          AND question_text = ?
                        LIMIT 1
                    ');
                    if (!$duplicateStmt) {
                        throw new Exception('Database error: ' . $conn->error, 500);
                    }
                    $duplicateStmt->bind_param(
                        'iisiis',
                        $toCompanyId,
                        $targetSectionId,
                        $kq['department'],
                        $kq['supervisor_id'],
                        $kq['staff_user_id'],
                        $kq['question_text']
                    );
                    $duplicateStmt->execute();
                    $alreadyExists = $duplicateStmt->get_result()->num_rows > 0;
                    $duplicateStmt->close();

                    if ($alreadyExists) {
                        $stats['kpi_questions_skipped']++;
                        continue;
                    }

                    $insertKpiStmt = $conn->prepare('
                        INSERT INTO kpi_questions
                            (company_id, section_id, department, supervisor_id, staff_user_id,
                             question_text, sort_order, is_active, created_by, updated_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ');
                    if (!$insertKpiStmt) {
                        throw new Exception('Database error: ' . $conn->error, 500);
                    }
                    $insertKpiStmt->bind_param(
                        'iisiisiiii',
                        $toCompanyId,
                        $targetSectionId,
                        $kq['department'],
                        $kq['supervisor_id'],
                        $kq['staff_user_id'],
                        $kq['question_text'],
                        $kq['sort_order'],
                        $kq['is_active'],
                        $loggedInUserId,
                        $loggedInUserId
                    );
                    if (!$insertKpiStmt->execute()) {
                        throw new Exception('Unable to copy KPI question: ' . $insertKpiStmt->error, 500);
                    }
                    $insertKpiStmt->close();
                    $stats['kpi_questions_copied']++;
                }
            }

            if ($copyGeneralQuestions && $section['type'] === 'general') {
                $generalStmt = $conn->prepare('
                    SELECT *
                    FROM general_questions
                    WHERE section_id = ?
                    ORDER BY sort_order ASC, id ASC
                ');
                if (!$generalStmt) {
                    throw new Exception('Database error: ' . $conn->error, 500);
                }
                $generalStmt->bind_param('i', $sourceSectionId);
                $generalStmt->execute();
                $generalQuestions = $generalStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $generalStmt->close();

                foreach ($generalQuestions as $gq) {
                    $duplicateStmt = $conn->prepare('
                        SELECT id
                        FROM general_questions
                        WHERE company_id = ?
                          AND section_id = ?
                          AND question_text = ?
                        LIMIT 1
                    ');
                    if (!$duplicateStmt) {
                        throw new Exception('Database error: ' . $conn->error, 500);
                    }
                    $duplicateStmt->bind_param('iis', $toCompanyId, $targetSectionId, $gq['question_text']);
                    $duplicateStmt->execute();
                    $alreadyExists = $duplicateStmt->get_result()->num_rows > 0;
                    $duplicateStmt->close();

                    if ($alreadyExists) {
                        $stats['general_questions_skipped']++;
                        continue;
                    }

                    $insertGeneralStmt = $conn->prepare('
                        INSERT INTO general_questions
                            (company_id, section_id, question_text, sort_order, is_active, created_by, updated_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ');
                    if (!$insertGeneralStmt) {
                        throw new Exception('Database error: ' . $conn->error, 500);
                    }
                    $insertGeneralStmt->bind_param(
                        'iisiiii',
                        $toCompanyId,
                        $targetSectionId,
                        $gq['question_text'],
                        $gq['sort_order'],
                        $gq['is_active'],
                        $loggedInUserId,
                        $loggedInUserId
                    );
                    if (!$insertGeneralStmt->execute()) {
                        throw new Exception('Unable to copy general question: ' . $insertGeneralStmt->error, 500);
                    }
                    $insertGeneralStmt->close();
                    $stats['general_questions_copied']++;
                }
            }
        }

        $logStmt = $conn->prepare('
            INSERT INTO audit_log
                (company_id, user_id, action, target_table, target_id, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        if ($logStmt) {
            $action = 'copy_cycle';
            $targetTable = 'appraisal_cycles';
            $description = sprintf(
                '%s copied cycle setup from %s (%d) to %s (%d). Sections: %d copied, %d reused, %d skipped. KPI questions: %d copied, %d already present. General questions: %d copied, %d already present.',
                $loggedInUserEmail !== '' ? $loggedInUserEmail : 'System user',
                (string) $fromCycle['title'],
                (int) $fromCycle['year'],
                (string) $toCycle['title'],
                (int) $toCycle['year'],
                $stats['sections_copied'],
                $stats['sections_reused'],
                $stats['sections_skipped'],
                $stats['kpi_questions_copied'],
                $stats['kpi_questions_skipped'],
                $stats['general_questions_copied'],
                $stats['general_questions_skipped']
            );
            $logStmt->bind_param(
                'iissis',
                $toCompanyId,
                $loggedInUserId,
                $action,
                $targetTable,
                $toCycleId,
                $description
            );
            $logStmt->execute();
            $logStmt->close();
        }

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            'status' => 'Success',
            'message' => 'Cycle setup copied successfully.',
            'data' => [
                'from_cycle' => ['id' => $fromCycleId, 'year' => $fromCycle['year']],
                'to_cycle' => ['id' => $toCycleId, 'year' => $toCycle['year']],
                'stats' => $stats,
            ],
        ]);
    } catch (Throwable $transactionError) {
        $conn->rollback();
        throw $transactionError;
    }
} catch (Throwable $e) {
    error_log('CopyCycle Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code <= 599) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'Failed',
        'message' => $e->getMessage(),
    ]);
}
