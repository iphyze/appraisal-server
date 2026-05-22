<?php

function apEsc($conn, $value) { return $conn->real_escape_string(trim((string)$value)); }
function apFetchOne($conn, $sql) { $r = $conn->query($sql); if (!$r) throw new Exception('Database error: '.$conn->error, 500); return $r->fetch_assoc(); }
function apFetchAll($conn, $sql) { $r = $conn->query($sql); if (!$r) throw new Exception('Database error: '.$conn->error, 500); $rows=[]; while($row=$r->fetch_assoc()) $rows[]=$row; return $rows; }
function apRoleKeySql($alias) { return "LOWER(REPLACE(TRIM({$alias}.name), ' ', '_'))"; }
function apFullName($row) { return trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: ($row['fullname'] ?? ''); }

function apEvaluationStatement($score) {
    $score = (float)$score;
    if ($score >= 4.6) return 'Significant / Outstanding';
    if ($score >= 3.1) return 'Consistently Exceeds Requirements';
    if ($score >= 2.1) return 'Completely Meet Requirements';
    if ($score >= 1.1) return 'Marginally Meets Requirements';
    return 'Requires Development';
}

function apDurationYears($dateOfJoining) {
    if (!$dateOfJoining) return null;
    try {
        $diff = (new DateTime($dateOfJoining))->diff(new DateTime());
        return round($diff->y + ($diff->m / 12), 2);
    } catch (Throwable $e) {
        return null;
    }
}

function apNormalizeSections($data) {
    if (!isset($data['sections']) || !is_array($data['sections']) || count($data['sections']) === 0) {
        throw new Exception("Field 'sections' is required and must be a non-empty array.", 400);
    }
    return $data['sections'];
}

function apSaveResponsesAndScores($conn, $appraisalId, $companyId, $cycleId, array $sections, array $context = []) {
    $appraisalId = (int)$appraisalId;
    $companyId = (int)$companyId;
    $cycleId = (int)$cycleId;
    $staffUserId = (int)($context['staff_user_id'] ?? 0);
    $supervisorId = (int)($context['supervisor_id'] ?? 0);
    $loggedInUserId = (int)($context['logged_in_user_id'] ?? 0);
    $staffDepartment = apEsc($conn, $context['staff_department'] ?? '');

    $sectionRows = apFetchAll($conn, "
        SELECT id, code, label, type, weight
        FROM appraisal_sections
        WHERE company_id = {$companyId}
          AND cycle_id = {$cycleId}
          AND is_active = 1
    ");

    $sectionMap = [];
    foreach ($sectionRows as $section) $sectionMap[(int)$section['id']] = $section;

    if (empty($sectionMap)) throw new Exception('No active appraisal sections found for this cycle.', 400);

    $conn->query("DELETE FROM appraisal_section_scores WHERE appraisal_id = {$appraisalId}");
    $conn->query("DELETE FROM appraisal_section_responses WHERE appraisal_id = {$appraisalId}");
    $conn->query("DELETE FROM appraisal_kpi_responses WHERE appraisal_id = {$appraisalId}");

    $sectionScores = [];
    $summary = 0;
    $kpiRating = null;
    $kpiSnapshot = [];

    foreach ($sections as $sectionPayload) {
        if (!isset($sectionPayload['section_id'], $sectionPayload['responses']) || !is_array($sectionPayload['responses'])) {
            throw new Exception("Each section must contain section_id and responses.", 400);
        }

        $sectionId = (int)$sectionPayload['section_id'];
        if (!isset($sectionMap[$sectionId])) throw new Exception("Section ID {$sectionId} is not valid for this cycle.", 400);

        $section = $sectionMap[$sectionId];
        $responses = $sectionPayload['responses'];
        if (count($responses) === 0) throw new Exception("Section {$section['code']} must have at least one rating.", 400);

        $ratings = [];
        foreach ($responses as $response) {
            if (!isset($response['question_id'], $response['rating'])) {
                throw new Exception("Each response must contain question_id and rating.", 400);
            }

            $questionId = (int)$response['question_id'];
            $rating = (float)$response['rating'];
            if ($rating < 1 || $rating > 5) throw new Exception('Rating must be between 1 and 5.', 400);

            if ($section['type'] === 'kpi') {
                $incomingText = trim((string)($response['question_text'] ?? ''));
                $isCustom = !empty($response['is_custom']);
                $isEdited = !empty($response['is_edited']);
                $question = null;

                if ($questionId > 0) {
                    $question = apFetchOne($conn, "
                        SELECT id, question_text
                        FROM kpi_questions
                        WHERE id = {$questionId}
                          AND company_id = {$companyId}
                          AND section_id = {$sectionId}
                        LIMIT 1
                    ");
                }

                if ($incomingText === '' && $question) $incomingText = $question['question_text'];
                if ($incomingText === '') throw new Exception("KPI question text is required for section {$section['code']}.", 400);

                // Supervisors can customize KPI questions per staff appraisal. To preserve history and FK integrity,
                // edited/new KPI questions are saved as staff-specific KPI question records, then referenced in the response.
                if (!$question || $isCustom || $isEdited || trim((string)$question['question_text']) !== $incomingText) {
                    $questionTextForInsert = apEsc($conn, $incomingText);
                    $sortOrder = isset($response['sort_order']) ? (int)$response['sort_order'] : 0;
                    $departmentSql = $staffDepartment !== '' ? "'{$staffDepartment}'" : "''";
                    $supervisorSql = $supervisorId > 0 ? (int)$supervisorId : 'NULL';
                    $staffSql = $staffUserId > 0 ? (int)$staffUserId : 'NULL';
                    $createdBySql = $loggedInUserId > 0 ? (int)$loggedInUserId : 'NULL';

                    $conn->query("INSERT INTO kpi_questions (company_id, section_id, department, supervisor_id, staff_user_id, question_text, sort_order, is_active, created_by, updated_by) VALUES ({$companyId}, {$sectionId}, {$departmentSql}, {$supervisorSql}, {$staffSql}, '{$questionTextForInsert}', {$sortOrder}, 1, {$createdBySql}, {$createdBySql})");
                    $questionId = (int)$conn->insert_id;
                    $question = ['id' => $questionId, 'question_text' => $incomingText];
                }

                if ($questionId <= 0) throw new Exception("Unable to resolve KPI question for section {$section['code']}.", 400);
                $questionText = apEsc($conn, $incomingText);
                $conn->query("INSERT INTO appraisal_kpi_responses (appraisal_id, section_id, kpi_question_id, question_text, rating) VALUES ({$appraisalId}, {$sectionId}, {$questionId}, '{$questionText}', {$rating})");
                $kpiSnapshot[] = $incomingText;
            } else {
                if ($questionId <= 0) throw new Exception('Invalid question selected.', 400);
                $question = apFetchOne($conn, "
                    SELECT id, question_text
                    FROM general_questions
                    WHERE id = {$questionId}
                      AND company_id = {$companyId}
                      AND section_id = {$sectionId}
                    LIMIT 1
                ");
                if (!$question) throw new Exception("General question ID {$questionId} was not found for section {$section['code']}.", 400);
                $questionText = apEsc($conn, $question['question_text']);
                $conn->query("INSERT INTO appraisal_section_responses (appraisal_id, section_id, general_question_id, question_text, rating) VALUES ({$appraisalId}, {$sectionId}, {$questionId}, '{$questionText}', {$rating})");
            }

            $ratings[] = $rating;
        }

        $avg = count($ratings) ? array_sum($ratings) / count($ratings) : 0;
        $weighted = $avg * (((float)$section['weight']) / 100);
        $summary += $weighted;
        if ($section['type'] === 'kpi') $kpiRating = round($avg, 2);

        $scoreRow = [
            'section_id' => $sectionId,
            'section_code' => $section['code'],
            'section_label' => $section['label'],
            'section_weight' => (float)$section['weight'],
            'section_avg' => round($avg, 2),
            'weighted_score' => round($weighted, 4),
        ];
        $sectionScores[] = $scoreRow;

        $code = apEsc($conn, $scoreRow['section_code']);
        $label = apEsc($conn, $scoreRow['section_label']);
        $weight = (float)$scoreRow['section_weight'];
        $sectionAvg = (float)$scoreRow['section_avg'];
        $weightedScore = (float)$scoreRow['weighted_score'];
        $conn->query("INSERT INTO appraisal_section_scores (appraisal_id, section_id, section_code, section_label, section_weight, section_avg, weighted_score) VALUES ({$appraisalId}, {$sectionId}, '{$code}', '{$label}', {$weight}, {$sectionAvg}, {$weightedScore})");
    }

    $summary = round($summary, 2);
    return [
        'section_scores' => $sectionScores,
        'appraisal_summary' => $summary,
        'kpi_rating' => $kpiRating,
        'evaluation_statement' => apEvaluationStatement($summary),
        'kpi_questions_snapshot' => implode(', ', array_unique($kpiSnapshot)),
    ];
}

function apAudit($conn, $companyId, $userId, $action, $targetTable, $targetId, $description) {
    $action = apEsc($conn, $action);
    $targetTable = apEsc($conn, $targetTable);
    $description = apEsc($conn, $description);
    $companyId = $companyId ? (int)$companyId : 'NULL';
    $userId = $userId ? (int)$userId : 'NULL';
    $targetId = $targetId ? (int)$targetId : 0;
    $conn->query("INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description) VALUES ({$companyId}, {$userId}, '{$action}', '{$targetTable}', {$targetId}, '{$description}')");
}

function apSendStaffEmail($kind, array $vars) {
    if (empty($vars['staff_email']) || !function_exists('sendMail')) return null;
    $companyName = $vars['company_name'] ?? 'Lambert Electromec Ltd';
    $subject = $kind === 'update'
        ? "Your Appraisal Has Been Updated — {$companyName}"
        : "Your Appraisal Has Been Submitted — {$companyName}";
    $html = $kind === 'update' && function_exists('getAppraisalUpdatedEmail')
        ? getAppraisalUpdatedEmail($vars)
        : (function_exists('getAppraisalSubmittedEmail') ? getAppraisalSubmittedEmail($vars) : '');
    if (!$html) return null;
    $result = sendMail($vars['staff_email'], $subject, $html, $companyName);
    return $result === true ? 'sent' : 'failed: ' . $result;
}
