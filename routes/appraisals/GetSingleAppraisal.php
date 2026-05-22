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
               sup.email AS supervisor_email, sup.department AS supervisor_department
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
        SELECT section_id, section_code, section_label, section_weight, section_avg, weighted_score
        FROM appraisal_section_scores
        WHERE appraisal_id = {$id}
        ORDER BY section_code ASC
    ");

    if (in_array($loggedInRoleKey, ['staff', 'supervisor'], true)) {
        foreach ($scores as &$score) {
            unset($score['weighted_score']);
            if ($loggedInRoleKey === 'staff') unset($score['section_weight']);
        }
        unset($score);
    }

    $general = apFetchAll($conn, "
        SELECT r.section_id, s.code AS section_code, s.label AS section_label, r.general_question_id AS question_id, r.question_text, r.rating
        FROM appraisal_section_responses r
        INNER JOIN appraisal_sections s ON s.id = r.section_id
        WHERE r.appraisal_id = {$id}
        ORDER BY s.sort_order ASC, r.id ASC
    ");

    $kpi = apFetchAll($conn, "
        SELECT r.section_id, s.code AS section_code, s.label AS section_label, r.kpi_question_id AS question_id, r.question_text, r.rating
        FROM appraisal_kpi_responses r
        INNER JOIN appraisal_sections s ON s.id = r.section_id
        WHERE r.appraisal_id = {$id}
        ORDER BY s.sort_order ASC, r.id ASC
    ");

    $grouped = [];
    foreach (array_merge($kpi, $general) as $row) {
        $sid = (int)$row['section_id'];
        if (!isset($grouped[$sid])) {
            $grouped[$sid] = [
                'section_id' => $sid,
                'section_code' => $row['section_code'],
                'section_label' => $row['section_label'],
                'responses' => [],
            ];
        }
        $grouped[$sid]['responses'][] = [
            'question_id' => (int)$row['question_id'],
            'question_text' => $row['question_text'],
            'rating' => $row['rating'],
        ];
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
