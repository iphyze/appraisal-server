<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/AppraisalHelpers.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Bad Request: Only GET method is allowed', 405);

    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];
    $loggedInRole = $userData['role'] ?? '';
    $loggedInRoleKey = strtolower(str_replace(' ', '_', trim((string)$loggedInRole)));
    $loggedInCompanyId = isset($userData['company_id']) ? (int)$userData['company_id'] : 0;

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) throw new Exception("Missing required parameter: 'id'.", 400);

    $appraisal = apFetchOne($conn, "
        SELECT ap.*, ac.year AS cycle_year, ac.title AS cycle_title, ac.start_date AS cycle_start_date, ac.end_date AS cycle_end_date,
               c.name AS company_name, c.code AS company_code,
               TRIM(CONCAT(COALESCE(sup.first_name,''), ' ', COALESCE(sup.last_name,''))) AS supervisor_name,
               sup.email AS supervisor_email, sup.department AS supervisor_department,
               CASE
                   WHEN ap.supervisor_id = {$loggedInUserId}
                    AND '{$loggedInRoleKey}' IN ('admin', 'supervisor')
                    AND EXISTS (
                        SELECT 1
                        FROM supervisor_assignments sa
                        WHERE sa.cycle_id = ap.cycle_id
                          AND sa.staff_id = ap.staff_user_id
                          AND sa.supervisor_id = ap.supervisor_id
                        LIMIT 1
                    )
                    AND EXISTS (
                        SELECT 1
                        FROM supervisor_onboarding so
                        WHERE so.cycle_id = ap.cycle_id
                          AND so.supervisor_id = ap.supervisor_id
                        LIMIT 1
                    )
                   THEN 1
                   ELSE 0
               END AS can_manage_appraisal
        FROM appraisals ap
        INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id
        INNER JOIN companies c ON c.id = ap.company_id
        LEFT JOIN users sup ON sup.id = ap.supervisor_id
        WHERE ap.id = {$id}
        LIMIT 1
    ");
    if (!$appraisal) throw new Exception('Appraisal not found.', 404);

    if ($loggedInRoleKey === 'super_admin') {
        $companyScope = resolveCompanyScope($userData);
        if ($companyScope !== null && (int) $appraisal['company_id'] !== (int) $companyScope) {
            throw new Exception('Unauthorized: This appraisal is outside the selected company context.', 403);
        }
    }
    if ($loggedInRoleKey === 'staff' && (int)$appraisal['staff_user_id'] !== $loggedInUserId) throw new Exception('Unauthorized: You can only view your own appraisal.', 403);
    if ($loggedInRoleKey === 'supervisor' && (int)$appraisal['supervisor_id'] !== $loggedInUserId && (int)$appraisal['staff_user_id'] !== $loggedInUserId) throw new Exception('Unauthorized: You can only view your own appraisal or appraisals you conducted.', 403);
    if ($loggedInRoleKey === 'admin' && (int)$appraisal['company_id'] !== $loggedInCompanyId) throw new Exception('Unauthorized: This appraisal does not belong to your company.', 403);
    if ($loggedInRoleKey === 'admin' && (int) $appraisal['staff_user_id'] !== $loggedInUserId) {
        $adminScope = trim((string) ($userData['staff_scope'] ?? 'All'));
        if (in_array($adminScope, ['Local', 'Expatriate'], true)) {
            $subject = apFetchOne($conn, "SELECT r.name AS role_name, u.staff_type FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE u.id = " . (int) $appraisal['staff_user_id'] . " LIMIT 1");
            if (
                $subject &&
                authRoleKey($subject['role_name'] ?? '') === 'staff' &&
                (string) ($subject['staff_type'] ?? $appraisal['staff_type'] ?? '') !== $adminScope
            ) {
                throw new Exception('Unauthorized: This appraisal is outside your staff scope.', 403);
            }
        }
    }

    $scores = apFetchAll($conn, "
        SELECT sc.section_id, sc.section_code, sc.section_label, sc.section_weight,
               sc.section_avg, sc.weighted_score, sc.rating_mode, sc.overall_rating,
               s.type AS section_type, s.sort_order
        FROM appraisal_section_scores sc
        LEFT JOIN appraisal_sections s ON s.id = sc.section_id
        WHERE sc.appraisal_id = {$id}
        ORDER BY COALESCE(s.sort_order, 999), sc.section_code ASC
    ");

    $general = apFetchAll($conn, "
        SELECT r.section_id, s.code AS section_code, s.label AS section_label, s.type AS section_type,
               r.general_question_id AS question_id, r.question_text, r.rating
        FROM appraisal_section_responses r
        LEFT JOIN appraisal_sections s ON s.id = r.section_id
        WHERE r.appraisal_id = {$id}
        ORDER BY COALESCE(s.sort_order, 999), r.id ASC
    ");

    $kpi = apFetchAll($conn, "
        SELECT r.section_id, s.code AS section_code, s.label AS section_label, s.type AS section_type,
               r.kpi_question_id AS question_id, r.question_text, r.rating
        FROM appraisal_kpi_responses r
        LEFT JOIN appraisal_sections s ON s.id = r.section_id
        WHERE r.appraisal_id = {$id}
        ORDER BY COALESCE(s.sort_order, 999), r.id ASC
    ");

    $grouped = [];

    // Always initialise sections from saved section scores. This keeps historical
    // appraisals visible even when the old system did not store question responses.
    foreach ($scores as $score) {
        $sid = (int)$score['section_id'];
        $grouped[$sid] = [
            'section_id' => $sid,
            'section_code' => $score['section_code'],
            'section_label' => $score['section_label'],
            'section_type' => $score['section_type'] ?? 'general',
            'rating_mode' => $score['rating_mode'] ?? 'historical_summary',
            'overall_rating' => $score['overall_rating'] ?? $score['section_avg'],
            'section_avg' => $score['section_avg'],
            'responses' => [],
            'is_historical_reference' => false,
        ];
    }

    foreach (array_merge($kpi, $general) as $row) {
        $sid = (int)$row['section_id'];

        if (!isset($grouped[$sid])) {
            $grouped[$sid] = [
                'section_id' => $sid,
                'section_code' => $row['section_code'],
                'section_label' => $row['section_label'],
                'section_type' => $row['section_type'] ?? 'general',
                'rating_mode' => 'per_question',
                'overall_rating' => null,
                'section_avg' => null,
                'responses' => [],
                'is_historical_reference' => false,
            ];
        }

        $grouped[$sid]['responses'][] = [
            'question_id' => (int)$row['question_id'],
            'question_text' => $row['question_text'],
            'rating' => $row['rating'],
            'is_reference' => false,
        ];
    }

    /*
     * Imported legacy appraisals carry accurate section scores, but the old
     * source did not retain dependable question-by-question ratings. Where
     * response snapshots are absent, show the questions configured for that
     * historical cycle as reference information and keep the saved section
     * score as the authoritative rating.
     */
    foreach ($grouped as $sid => &$sectionGroup) {
        if (!empty($sectionGroup['responses'])) {
            continue;
        }

        $sectionGroup['is_historical_reference'] = true;
        if ($sectionGroup['rating_mode'] === 'per_question') {
            $sectionGroup['rating_mode'] = 'historical_summary';
        }

        if (($sectionGroup['section_type'] ?? '') === 'kpi') {
            $snapshotLines = apHistoricalKpiSnapshotLines(
                $appraisal['kpi_questions_snapshot'] ?? ''
            );

            if (!empty($snapshotLines)) {
                foreach ($snapshotLines as $index => $questionText) {
                    $sectionGroup['responses'][] = [
                        'question_id' => 0,
                        'question_text' => $questionText,
                        'rating' => null,
                        'is_reference' => true,
                        'reference_source' => 'historical_snapshot',
                        'sort_order' => $index + 1,
                    ];
                }
            } else {
                $referenceQuestions = apHistoricalKpiReferenceQuestions(
                    $conn,
                    (int) $appraisal['company_id'],
                    (int) $sid,
                    (int) $appraisal['staff_user_id'],
                    (int) $appraisal['supervisor_id'],
                    (string) ($appraisal['staff_department'] ?? '')
                );

                foreach ($referenceQuestions as $question) {
                    $sectionGroup['responses'][] = [
                        'question_id' => (int) $question['id'],
                        'question_text' => $question['question_text'],
                        'rating' => null,
                        'is_reference' => true,
                        'reference_source' => 'configured_question_bank',
                    ];
                }
            }
        } else {
            $referenceQuestions = apFetchAll($conn, "
                SELECT id, question_text
                FROM general_questions
                WHERE section_id = {$sid}
                  AND company_id = " . (int)$appraisal['company_id'] . "
                ORDER BY sort_order ASC, id ASC
            ");

            foreach ($referenceQuestions as $question) {
                $sectionGroup['responses'][] = [
                    'question_id' => (int)$question['id'],
                    'question_text' => $question['question_text'],
                    'rating' => null,
                    'is_reference' => true,
                ];
            }
        }
    }
    unset($sectionGroup);

    if (in_array($loggedInRoleKey, ['staff', 'supervisor'], true)) {
        foreach ($scores as &$score) {
            unset($score['weighted_score']);
            if ($loggedInRoleKey === 'staff') {
                unset($score['section_weight']);
            }
        }
        unset($score);
    }

    $appraisal['section_scores'] = $scores;
    $appraisal['general_responses'] = $general;
    $appraisal['kpi_responses'] = $kpi;
    $appraisal['sections'] = array_values($grouped);

    http_response_code(200);
    echo json_encode(['status' => 'Success', 'message' => 'Appraisal fetched successfully', 'data' => $appraisal]);

} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
