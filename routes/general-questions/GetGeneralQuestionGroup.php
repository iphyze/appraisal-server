<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Bad Request: Only GET method is allowed', 400);
    }

    $userData = authenticateUser();

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("Missing required parameter: 'id'.", 400);
    }

    $id = (int) $_GET['id'];
    if ($id <= 0) throw new Exception('Invalid general question set ID.', 400);

    // Accept any question ID in the set so existing deep links remain valid.
    // The grouped overview uses MIN(question.id) as the stable representative ID.
    $anchorStmt = $conn->prepare("
        SELECT
            gq.id,
            gq.company_id,
            gq.section_id,
            s.code AS section_code,
            s.label AS section_label,
            s.weight AS section_weight,
            ac.id AS cycle_id,
            ac.year AS cycle_year,
            ac.title AS cycle_title,
            c.code AS company_code,
            c.name AS company_name
        FROM general_questions gq
        INNER JOIN appraisal_sections s ON s.id = gq.section_id
        INNER JOIN appraisal_cycles ac ON ac.id = s.cycle_id
        INNER JOIN companies c ON c.id = gq.company_id
        WHERE gq.id = ?
        LIMIT 1
    ");
    if (!$anchorStmt) throw new Exception('Database error: ' . $conn->error, 500);
    $anchorStmt->bind_param('i', $id);
    $anchorStmt->execute();
    $anchor = $anchorStmt->get_result()->fetch_assoc();
    $anchorStmt->close();

    if (!$anchor) throw new Exception('General question set not found.', 404);

    $companyScope = resolveCompanyScope($userData);
    if ($companyScope !== null && (int) $anchor['company_id'] !== (int) $companyScope) {
        throw new Exception('Unauthorized: This general question set is outside the selected company scope.', 403);
    }

    $groupStmt = $conn->prepare("
        SELECT
            gq.id,
            gq.question_text,
            gq.sort_order,
            gq.is_active,
            gq.created_at,
            gq.updated_at
        FROM general_questions gq
        WHERE gq.company_id = ?
          AND gq.section_id = ?
        ORDER BY gq.sort_order ASC, gq.id ASC
    ");
    if (!$groupStmt) throw new Exception('Database error: ' . $conn->error, 500);
    $companyId = (int) $anchor['company_id'];
    $sectionId = (int) $anchor['section_id'];
    $groupStmt->bind_param('ii', $companyId, $sectionId);
    $groupStmt->execute();
    $questions = $groupStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $groupStmt->close();

    if (!$questions) throw new Exception('General question set not found.', 404);

    $questionCount = count($questions);
    $activeCount = 0;
    $createdAt = null;
    $updatedAt = null;
    $groupId = null;

    foreach ($questions as $question) {
        $questionId = (int) $question['id'];
        $groupId = $groupId === null ? $questionId : min($groupId, $questionId);
        if ((int) $question['is_active'] === 1) $activeCount++;
        if ($createdAt === null || $question['created_at'] < $createdAt) $createdAt = $question['created_at'];
        if ($updatedAt === null || $question['updated_at'] > $updatedAt) $updatedAt = $question['updated_at'];
    }

    $groupStatus = $activeCount === $questionCount
        ? 'active'
        : ($activeCount === 0 ? 'inactive' : 'mixed');

    $group = [
        'id' => $groupId,
        'group_id' => $groupId,
        'company_id' => $companyId,
        'company_code' => $anchor['company_code'],
        'company_name' => $anchor['company_name'],
        'section_id' => $sectionId,
        'section_code' => $anchor['section_code'],
        'section_label' => $anchor['section_label'],
        'section_weight' => $anchor['section_weight'],
        'cycle_id' => (int) $anchor['cycle_id'],
        'cycle_year' => $anchor['cycle_year'],
        'cycle_title' => $anchor['cycle_title'],
        'group_status' => $groupStatus,
        'question_count' => $questionCount,
        'active_count' => $activeCount,
        'inactive_count' => $questionCount - $activeCount,
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
        'questions' => $questions,
    ];

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'General question set fetched successfully',
        'data' => $group,
    ]);
} catch (Exception $e) {
    error_log('GetGeneralQuestionGroup Error: ' . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
