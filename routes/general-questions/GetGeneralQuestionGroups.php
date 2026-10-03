<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Bad Request: Only GET method is allowed', 400);
    }

    $userData     = authenticateUser();
    $companyScope = resolveCompanyScope($userData);
    $clause       = buildCompanyWhereClause($companyScope, 'gq');

    $sectionId = isset($_GET['section_id']) ? (int) $_GET['section_id'] : null;
    $cycleId   = isset($_GET['cycle_id']) ? (int) $_GET['cycle_id'] : null;
    $isActive  = isset($_GET['is_active']) && $_GET['is_active'] !== '' ? (int) $_GET['is_active'] : null;
    $search    = isset($_GET['search']) ? trim($_GET['search']) : '';

    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
    $page  = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    if ($limit <= 0) $limit = 20;
    if ($page <= 0) $page = 1;
    $offset = ($page - 1) * $limit;

    $allowedSort = ['id', 'sort_order', 'created_at'];
    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSort, true)
        ? $_GET['sortBy']
        : 'sort_order';
    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === 'DESC'
        ? 'DESC'
        : 'ASC';

    $baseQuery = "
        FROM general_questions gq
        INNER JOIN appraisal_sections s ON s.id = gq.section_id
        INNER JOIN appraisal_cycles ac ON ac.id = s.cycle_id
        INNER JOIN companies c ON c.id = gq.company_id
        WHERE 1=1
    ";
    $params = [];
    $types = '';

    if ($clause['value'] !== null) {
        $baseQuery .= ' AND gq.company_id = ?';
        $params[] = $clause['value'];
        $types .= 'i';
    }

    if ($sectionId) {
        $baseQuery .= ' AND gq.section_id = ?';
        $params[] = $sectionId;
        $types .= 'i';
    }

    if ($cycleId) {
        $baseQuery .= ' AND s.cycle_id = ?';
        $params[] = $cycleId;
        $types .= 'i';
    }

    $groupBy = "
        GROUP BY
            gq.company_id,
            gq.section_id,
            s.code,
            s.label,
            s.weight,
            ac.id,
            ac.year,
            ac.title,
            c.code,
            c.name
    ";

    $having = [];
    $havingParams = [];
    $havingTypes = '';

    if ($search !== '') {
        $having[] = 'SUM(CASE WHEN gq.question_text LIKE ? THEN 1 ELSE 0 END) > 0';
        $havingParams[] = '%' . $search . '%';
        $havingTypes .= 's';
    }

    if ($isActive !== null) {
        if ($isActive === 1) {
            $having[] = 'SUM(CASE WHEN gq.is_active = 1 THEN 1 ELSE 0 END) = COUNT(*)';
        } else {
            $having[] = 'SUM(CASE WHEN gq.is_active = 1 THEN 1 ELSE 0 END) = 0';
        }
    }

    $havingSql = empty($having) ? '' : ' HAVING ' . implode(' AND ', $having);

    $countQuery = "
        SELECT COUNT(*) AS total, COALESCE(SUM(grouped.question_count), 0) AS total_questions
        FROM (
            SELECT COUNT(*) AS question_count
            {$baseQuery}
            {$groupBy}
            {$havingSql}
        ) grouped
    ";
    $countStmt = $conn->prepare($countQuery);
    if (!$countStmt) throw new Exception('Database error: ' . $conn->error, 500);
    $countParams = array_merge($params, $havingParams);
    $countTypes = $types . $havingTypes;
    if (!empty($countParams)) $countStmt->bind_param($countTypes, ...$countParams);
    $countStmt->execute();
    $countRow = $countStmt->get_result()->fetch_assoc();
    $total = (int) ($countRow['total'] ?? 0);
    $totalQuestions = (int) ($countRow['total_questions'] ?? 0);
    $countStmt->close();

    $sortMap = [
        'id' => 'id',
        'sort_order' => 'sort_order',
        'created_at' => 'created_at',
    ];
    $orderExpression = $sortMap[$sortBy] ?? 'sort_order';

    $dataQuery = "
        SELECT
            MIN(gq.id) AS id,
            MIN(gq.id) AS group_id,
            gq.company_id,
            gq.section_id,
            MIN(gq.sort_order) AS sort_order,
            MIN(gq.created_at) AS created_at,
            MAX(gq.updated_at) AS updated_at,
            COUNT(*) AS question_count,
            SUM(CASE WHEN gq.is_active = 1 THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN gq.is_active = 0 THEN 1 ELSE 0 END) AS inactive_count,
            s.code AS section_code,
            s.label AS section_label,
            s.weight AS section_weight,
            ac.id AS cycle_id,
            ac.year AS cycle_year,
            ac.title AS cycle_title,
            c.code AS company_code,
            c.name AS company_name,
            CASE
                WHEN SUM(CASE WHEN gq.is_active = 1 THEN 1 ELSE 0 END) = COUNT(*) THEN 'active'
                WHEN SUM(CASE WHEN gq.is_active = 1 THEN 1 ELSE 0 END) = 0 THEN 'inactive'
                ELSE 'mixed'
            END AS group_status
        {$baseQuery}
        {$groupBy}
        {$havingSql}
        ORDER BY {$orderExpression} {$sortOrder}, id ASC
        LIMIT ? OFFSET ?
    ";

    $dataStmt = $conn->prepare($dataQuery);
    if (!$dataStmt) throw new Exception('Database error: ' . $conn->error, 500);
    $dataParams = array_merge($params, $havingParams, [$limit, $offset]);
    $dataTypes = $types . $havingTypes . 'ii';
    $dataStmt->bind_param($dataTypes, ...$dataParams);
    $dataStmt->execute();
    $data = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'General question sets fetched successfully',
        'data' => $data,
        'meta' => [
            'total' => $total,
            'total_questions' => $totalQuestions,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => (int) ceil($total / $limit),
            'sortBy' => $sortBy,
            'sortOrder' => $sortOrder,
            'filters' => [
                'section_id' => $sectionId,
                'cycle_id' => $cycleId,
                'is_active' => $isActive,
                'search' => $search ?: null,
                'company_scope' => $companyScope,
            ],
        ],
    ]);
} catch (Exception $e) {
    error_log('GetGeneralQuestionGroups Error: ' . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
