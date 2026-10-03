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
    if ($id <= 0) throw new Exception('Invalid KPI question set ID.', 400);

    // The route intentionally accepts any question ID from the set. This keeps
    // existing links backward compatible while allowing the grouped overview to
    // use MIN(question.id) as its stable representative ID.
    $anchorStmt = $conn->prepare("
        SELECT
            kq.id,
            kq.company_id,
            kq.section_id,
            kq.department,
            kq.supervisor_id,
            kq.staff_user_id,
            s.code AS section_code,
            s.label AS section_label,
            s.weight AS section_weight,
            ac.id AS cycle_id,
            ac.year AS cycle_year,
            ac.title AS cycle_title,
            c.code AS company_code,
            c.name AS company_name,
            CONCAT(sup.first_name, ' ', sup.last_name) AS supervisor_name,
            sup.email AS supervisor_email,
            CONCAT(stf.first_name, ' ', stf.last_name) AS staff_name,
            stf.email AS staff_email
        FROM kpi_questions kq
        INNER JOIN appraisal_sections s ON s.id = kq.section_id
        INNER JOIN appraisal_cycles ac ON ac.id = s.cycle_id
        INNER JOIN companies c ON c.id = kq.company_id
        LEFT JOIN users sup ON sup.id = kq.supervisor_id
        LEFT JOIN users stf ON stf.id = kq.staff_user_id
        WHERE kq.id = ?
        LIMIT 1
    ");
    if (!$anchorStmt) throw new Exception('Database error: ' . $conn->error, 500);
    $anchorStmt->bind_param('i', $id);
    $anchorStmt->execute();
    $anchor = $anchorStmt->get_result()->fetch_assoc();
    $anchorStmt->close();

    if (!$anchor) throw new Exception('KPI question set not found.', 404);

    $companyScope = resolveCompanyScope($userData);
    if ($companyScope !== null && (int) $anchor['company_id'] !== (int) $companyScope) {
        throw new Exception('Unauthorized: This KPI question set is outside the selected company scope.', 403);
    }

    $scopeSql = '';
    $scopeParams = [];
    $scopeTypes = '';

    if ($anchor['supervisor_id'] === null) {
        $scopeSql .= ' AND kq.supervisor_id IS NULL';
    } else {
        $scopeSql .= ' AND kq.supervisor_id = ?';
        $scopeParams[] = (int) $anchor['supervisor_id'];
        $scopeTypes .= 'i';
    }

    if ($anchor['staff_user_id'] === null) {
        $scopeSql .= ' AND kq.staff_user_id IS NULL';
    } else {
        $scopeSql .= ' AND kq.staff_user_id = ?';
        $scopeParams[] = (int) $anchor['staff_user_id'];
        $scopeTypes .= 'i';
    }

    $groupStmt = $conn->prepare("
        SELECT
            kq.id,
            kq.question_text,
            kq.weight_percent,
            kq.sort_order,
            kq.is_active,
            kq.created_at,
            kq.updated_at
        FROM kpi_questions kq
        WHERE kq.company_id = ?
          AND kq.section_id = ?
          AND kq.department = ?
          {$scopeSql}
        ORDER BY kq.sort_order ASC, kq.id ASC
    ");
    if (!$groupStmt) throw new Exception('Database error: ' . $conn->error, 500);

    $groupParams = array_merge(
        [(int) $anchor['company_id'], (int) $anchor['section_id'], $anchor['department']],
        $scopeParams
    );
    $groupTypes = 'iis' . $scopeTypes;
    $groupStmt->bind_param($groupTypes, ...$groupParams);
    $groupStmt->execute();
    $questions = $groupStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $groupStmt->close();

    if (!$questions) throw new Exception('KPI question set not found.', 404);

    $questionCount = count($questions);
    $activeCount = 0;
    $activeWeightedCount = 0;
    $createdAt = null;
    $updatedAt = null;
    $groupId = null;

    foreach ($questions as $question) {
        $questionId = (int) $question['id'];
        $groupId = $groupId === null ? $questionId : min($groupId, $questionId);

        if ((int) $question['is_active'] === 1) {
            $activeCount++;
            if ($question['weight_percent'] !== null && $question['weight_percent'] !== '') {
                $activeWeightedCount++;
            }
        }

        if ($createdAt === null || $question['created_at'] < $createdAt) $createdAt = $question['created_at'];
        if ($updatedAt === null || $question['updated_at'] > $updatedAt) $updatedAt = $question['updated_at'];
    }

    $groupStatus = $activeCount === $questionCount
        ? 'active'
        : ($activeCount === 0 ? 'inactive' : 'mixed');

    if ($activeWeightedCount === 0) {
        $weightMode = 'equal';
    } elseif ($activeCount > 0 && $activeWeightedCount === $activeCount) {
        $weightMode = 'custom';
    } else {
        $weightMode = 'mixed';
    }

    $scope = $anchor['staff_user_id'] !== null
        ? 'individual'
        : ($anchor['supervisor_id'] !== null ? 'supervisor' : 'department');

    $group = [
        'id' => $groupId,
        'group_id' => $groupId,
        'company_id' => (int) $anchor['company_id'],
        'company_code' => $anchor['company_code'],
        'company_name' => $anchor['company_name'],
        'section_id' => (int) $anchor['section_id'],
        'section_code' => $anchor['section_code'],
        'section_label' => $anchor['section_label'],
        'section_weight' => $anchor['section_weight'],
        'cycle_id' => (int) $anchor['cycle_id'],
        'cycle_year' => $anchor['cycle_year'],
        'cycle_title' => $anchor['cycle_title'],
        'department' => $anchor['department'],
        'supervisor_id' => $anchor['supervisor_id'] !== null ? (int) $anchor['supervisor_id'] : null,
        'supervisor_name' => $anchor['supervisor_name'],
        'supervisor_email' => $anchor['supervisor_email'],
        'staff_user_id' => $anchor['staff_user_id'] !== null ? (int) $anchor['staff_user_id'] : null,
        'staff_name' => $anchor['staff_name'],
        'staff_email' => $anchor['staff_email'],
        'scope' => $scope,
        'group_status' => $groupStatus,
        'weight_mode' => $weightMode,
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
        'message' => 'KPI question set fetched successfully',
        'data' => $group,
    ]);
} catch (Exception $e) {
    error_log('GetKpiQuestionGroup Error: ' . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
