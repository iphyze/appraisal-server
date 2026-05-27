<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

function jsonResponse($status, $message, $data = [], $code = 200) {
    http_response_code($code);
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}
function esc($conn, $value) { return $conn->real_escape_string(trim((string)$value)); }
function fetchOneRaw($conn, $sql) { $r = $conn->query($sql); if (!$r) throw new Exception('Database error: '.$conn->error, 500); return $r->fetch_assoc(); }
function fetchAllRaw($conn, $sql) { $r = $conn->query($sql); if (!$r) throw new Exception('Database error: '.$conn->error, 500); $rows=[]; while($row=$r->fetch_assoc()) $rows[]=$row; return $rows; }
function roleKeySql($alias) { return "LOWER(REPLACE(TRIM({$alias}.name), ' ', '_'))"; }
function fullName($row) { return trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: ($row['fullname'] ?? ''); }

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Bad Request: Only GET method is allowed', 405);

    $userData = requireRoles(['super_admin', 'admin', 'supervisor']);
    $loggedInUserId = (int)$userData['id'];
    $loggedInRole = $userData['role'] ?? '';
    $loggedInRoleKey = strtolower(str_replace(' ', '_', trim((string)$loggedInRole)));
    $loggedInCompanyId = isset($userData['company_id']) ? (int)$userData['company_id'] : 0;

    $appraisalId = isset($_GET['appraisal_id']) ? (int)$_GET['appraisal_id'] : 0;
    $cycleId = isset($_GET['cycle_id']) ? (int)$_GET['cycle_id'] : 0;
    $staffUserId = isset($_GET['staff_user_id']) ? (int)$_GET['staff_user_id'] : 0;

    $existing = null;
    if ($appraisalId > 0) {
        $existing = fetchOneRaw($conn, "
            SELECT ap.*, ac.year AS cycle_year, ac.title AS cycle_title, c.name AS company_name, c.code AS company_code
            FROM appraisals ap
            INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id
            INNER JOIN companies c ON c.id = ap.company_id
            WHERE ap.id = {$appraisalId}
            LIMIT 1
        ");
        if (!$existing) throw new Exception('Appraisal not found.', 404);
        $cycleId = (int)$existing['cycle_id'];
        $staffUserId = (int)$existing['staff_user_id'];
    }

    if ($cycleId <= 0 || $staffUserId <= 0) {
        throw new Exception('cycle_id and staff_user_id are required.', 400);
    }

    $companyScope = resolveCompanyScope($userData);
    $cycleCompanySql = $companyScope !== null ? " AND company_id = " . (int) $companyScope : '';
    $cycle = fetchOneRaw($conn, "
        SELECT id, company_id, year, title, start_date, end_date, is_active
        FROM appraisal_cycles
        WHERE id = {$cycleId} {$cycleCompanySql}
        LIMIT 1
    ");
    if (!$cycle) throw new Exception('Selected appraisal cycle was not found or is outside your company scope.', 404);
    $companyId = (int)$cycle['company_id'];

    $staff = fetchOneRaw($conn, "
        SELECT u.id, u.first_name, u.last_name, u.fullname, u.email, u.staff_id, u.unique_ref,
               u.department, u.job_title, u.staff_type, u.location, u.date_of_joining, u.company_id,
               r.name AS role_name
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = {$staffUserId}
          AND u.company_id = {$companyId}
          AND u.is_active = 1
          AND " . appraiseeRoleWhere('r') . "
        LIMIT 1
    ");
    if (!$staff) throw new Exception('Staff member was not found for this cycle.', 404);

    // Administrator scope restricts regular staff only; administrators and
    // supervisors may still be appraised and viewed in their company.
    if ($loggedInRoleKey === 'admin') {
        $adminScope = trim((string) ($userData['staff_scope'] ?? 'All'));
        if (
            in_array($adminScope, ['Local', 'Expatriate'], true) &&
            authRoleKey($staff['role_name'] ?? '') === 'staff' &&
            (string) ($staff['staff_type'] ?? '') !== $adminScope
        ) {
            throw new Exception('Unauthorized: This employee is outside your staff scope.', 403);
        }
    }

    if ($loggedInRoleKey === 'supervisor') {
        $assignment = fetchOneRaw($conn, "
            SELECT id FROM supervisor_assignments
            WHERE cycle_id = {$cycleId}
              AND supervisor_id = {$loggedInUserId}
              AND staff_id = {$staffUserId}
            LIMIT 1
        ");
        if (!$assignment) throw new Exception('This staff member is not assigned to you for this appraisal cycle.', 403);

        $onboard = fetchOneRaw($conn, "
            SELECT id FROM supervisor_onboarding
            WHERE cycle_id = {$cycleId}
              AND supervisor_id = {$loggedInUserId}
            LIMIT 1
        ");
        if (!$onboard) throw new Exception('You need to complete supervisor onboarding before appraising staff for this cycle.', 403);

        if ($existing && (int)$existing['supervisor_id'] !== $loggedInUserId) {
            throw new Exception('Unauthorized: You can only edit appraisals you conducted.', 403);
        }
    } elseif ($loggedInRoleKey === 'admin' && $companyId !== $loggedInCompanyId) {
        throw new Exception('Unauthorized: This appraisal does not belong to your company.', 403);
    }

    $supervisorId = $existing ? (int)$existing['supervisor_id'] : 0;
    if ($supervisorId <= 0) {
        if ($loggedInRoleKey === 'supervisor') {
            $supervisorId = $loggedInUserId;
        } else {
            $supRow = fetchOneRaw($conn, "
                SELECT supervisor_id
                FROM supervisor_assignments
                WHERE cycle_id = {$cycleId}
                  AND staff_id = {$staffUserId}
                ORDER BY id ASC
                LIMIT 1
            " );
            if (!$supRow) throw new Exception('Assign this employee to an appraiser before starting the appraisal.', 400);
            $supervisorId = (int) $supRow['supervisor_id'];
        }
    }

    if (!$existing && $loggedInRoleKey !== 'supervisor') {
        $onboard = fetchOneRaw($conn, "SELECT id FROM supervisor_onboarding WHERE cycle_id = {$cycleId} AND supervisor_id = {$supervisorId} LIMIT 1");
        if (!$onboard) throw new Exception('The assigned appraiser must complete onboarding before this appraisal can begin.', 403);
    }

    $supervisor = fetchOneRaw($conn, "
        SELECT u.id, u.first_name, u.last_name, u.fullname, u.email, u.department, u.job_title, r.name AS role_name
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = {$supervisorId}
        LIMIT 1
    ");

    $existingGeneralRatings = [];
    $existingKpiRatings = [];
    $existingSectionRating = [];

    if ($existing) {
        $genRatings = fetchAllRaw($conn, "SELECT general_question_id, rating FROM appraisal_section_responses WHERE appraisal_id = {$appraisalId}");
        foreach ($genRatings as $row) $existingGeneralRatings[(int)$row['general_question_id']] = $row['rating'];

        $kpiRatings = fetchAllRaw($conn, "SELECT kpi_question_id, rating FROM appraisal_kpi_responses WHERE appraisal_id = {$appraisalId}");
        foreach ($kpiRatings as $row) $existingKpiRatings[(int)$row['kpi_question_id']] = $row['rating'];

        $scoreModes = fetchAllRaw($conn, "SELECT section_id, rating_mode, overall_rating, section_avg FROM appraisal_section_scores WHERE appraisal_id = {$appraisalId}");
        foreach ($scoreModes as $score) {
            $existingSectionRating[(int)$score['section_id']] = [
                'rating_mode' => ($score['rating_mode'] ?? 'per_question') === 'overall' ? 'overall' : 'per_question',
                'overall_rating' => ($score['rating_mode'] ?? '') === 'overall' ? ($score['overall_rating'] ?? $score['section_avg']) : '',
            ];
        }
    }

    $sections = fetchAllRaw($conn, "
        SELECT id, code, label, description, type, weight, sort_order
        FROM appraisal_sections
        WHERE company_id = {$companyId}
          AND cycle_id = {$cycleId}
          AND is_active = 1
        ORDER BY sort_order ASC, id ASC
    ");

    $dept = esc($conn, $staff['department'] ?? '');
    $formSections = [];
    foreach ($sections as $section) {
        $sectionId = (int)$section['id'];
        $questions = [];

        if ($section['type'] === 'general') {
            $questionRows = fetchAllRaw($conn, "
                SELECT id, question_text, sort_order
                FROM general_questions
                WHERE company_id = {$companyId}
                  AND section_id = {$sectionId}
                  AND is_active = 1
                ORDER BY sort_order ASC, id ASC
            ");
            foreach ($questionRows as $q) {
                $qid = (int)$q['id'];
                $questions[] = [
                    'id' => $qid,
                    'question_id' => $qid,
                    'question_type' => 'general',
                    'question_text' => $q['question_text'],
                    'sort_order' => (int)$q['sort_order'],
                    'rating' => $existingGeneralRatings[$qid] ?? '',
                ];
            }
        } else {
            if ($existing) {
                // During edit, load the exact KPI questions that were used for this staff appraisal.
                // This prevents removed default KPI questions from reappearing when the supervisor edits the appraisal later.
                $questionRows = fetchAllRaw($conn, "
                    SELECT r.kpi_question_id AS id, r.question_text, q.department, q.supervisor_id, q.staff_user_id, q.sort_order, r.rating,
                           CASE
                               WHEN q.staff_user_id = {$staffUserId} THEN 'individual'
                               WHEN q.supervisor_id = {$supervisorId} THEN 'supervisor'
                               ELSE 'appraisal'
                           END AS scope
                    FROM appraisal_kpi_responses r
                    LEFT JOIN kpi_questions q ON q.id = r.kpi_question_id
                    WHERE r.appraisal_id = {$appraisalId}
                      AND r.section_id = {$sectionId}
                    ORDER BY r.id ASC
                ");
            } else {
                $questionRows = fetchAllRaw($conn, "
                    SELECT DISTINCT q.id, q.question_text, q.department, q.supervisor_id, q.staff_user_id, q.sort_order, NULL AS rating,
                           CASE
                               WHEN q.staff_user_id = {$staffUserId} THEN 'individual'
                               WHEN q.supervisor_id = {$supervisorId} THEN 'supervisor'
                               ELSE 'department'
                           END AS scope
                    FROM kpi_questions q
                    LEFT JOIN staff_kpi_assignments ska
                        ON ska.kpi_question_id = q.id
                       AND ska.staff_user_id = {$staffUserId}
                       AND ska.section_id = q.section_id
                    WHERE q.company_id = {$companyId}
                      AND q.section_id = {$sectionId}
                      AND q.is_active = 1
                      AND (
                            q.staff_user_id = {$staffUserId}
                            OR ska.id IS NOT NULL
                            OR (q.supervisor_id = {$supervisorId} AND q.staff_user_id IS NULL)
                            OR (q.department = '{$dept}' AND q.supervisor_id IS NULL AND q.staff_user_id IS NULL)
                      )
                    ORDER BY scope ASC, q.sort_order ASC, q.id ASC
                ");
            }

            foreach ($questionRows as $q) {
                $qid = (int)$q['id'];
                $questions[] = [
                    'id' => $qid,
                    'question_id' => $qid,
                    'question_type' => 'kpi',
                    'question_text' => $q['question_text'],
                    'sort_order' => (int)($q['sort_order'] ?? 0),
                    'scope' => $q['scope'],
                    'rating' => $q['rating'] ?? ($existingKpiRatings[$qid] ?? ''),
                ];
            }
        }

        // New appraisals open in cumulative (overall section) mode by default.
        // When editing an existing appraisal, retain the mode already saved.
        $defaultRatingMode = $existing ? 'per_question' : 'overall';
        $savedMode = $existingSectionRating[$sectionId] ?? ['rating_mode' => $defaultRatingMode, 'overall_rating' => ''];
        $formSections[] = array_merge($section, [
            'rating_mode' => $savedMode['rating_mode'],
            'overall_rating' => $savedMode['overall_rating'],
            'questions' => $questions,
        ]);
    }

    jsonResponse('Success', 'Appraisal form data fetched successfully.', [
        'mode' => $existing ? 'edit' : 'create',
        'appraisal' => $existing,
        'cycle' => $cycle,
        'staff' => array_merge($staff, ['full_name' => fullName($staff)]),
        'supervisor' => $supervisor ? array_merge($supervisor, ['full_name' => fullName($supervisor)]) : null,
        'sections' => $formSections,
    ]);

} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    jsonResponse('Failed', $e->getMessage(), [], $code);
}
