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
    $clause       = buildCompanyWhereClause($companyScope, 'kq');

    $sectionId    = isset($_GET['section_id']) ? (int) $_GET['section_id'] : null;
    $cycleId      = isset($_GET['cycle_id']) ? (int) $_GET['cycle_id'] : null;
    $department   = isset($_GET['department']) ? trim($_GET['department']) : null;
    $supervisorId = isset($_GET['supervisor_id']) ? (int) $_GET['supervisor_id'] : null;
    $staffUserId  = isset($_GET['staff_user_id']) ? (int) $_GET['staff_user_id'] : null;
    $isActive     = isset($_GET['is_active']) && $_GET['is_active'] !== '' ? (int) $_GET['is_active'] : null;
    $search       = isset($_GET['search']) ? trim($_GET['search']) : '';

    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
    $page  = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    if ($limit <= 0) $limit = 20;
    if ($page <= 0) $page = 1;
    $offset = ($page - 1) * $limit;

    $allowedSort = ['id', 'department', 'sort_order', 'created_at'];
    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSort, true)
        ? $_GET['sortBy']
        : 'sort_order';
    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === 'DESC'
        ? 'DESC'
        : 'ASC';

    $baseQuery = "
        FROM kpi_questions kq
        INNER JOIN appraisal_sections s ON s.id = kq.section_id
        INNER JOIN appraisal_cycles ac ON ac.id = s.cycle_id
        INNER JOIN companies c ON c.id = kq.company_id
        LEFT JOIN users sup ON sup.id = kq.supervisor_id
        LEFT JOIN users stf ON stf.id = kq.staff_user_id
        WHERE 1=1
    ";
    $params = [];
    $types = '';

    if ($clause['value'] !== null) {
        $baseQuery .= ' AND kq.company_id = ?';
        $params[] = $clause['value'];
        $types .= 'i';
    }

    if ($sectionId) {
        $baseQuery .= ' AND kq.section_id = ?';
        $params[] = $sectionId;
        $types .= 'i';
    }

    if ($cycleId) {
        $baseQuery .= ' AND s.cycle_id = ?';
        $params[] = $cycleId;
        $types .= 'i';
    }

    if ($department) {
        $baseQuery .= ' AND kq.department = ?';
        $params[] = $department;
        $types .= 's';
    }

    if ($supervisorId) {
        $baseQuery .= ' AND kq.supervisor_id = ?';
        $params[] = $supervisorId;
        $types .= 'i';
    }

    if ($staffUserId) {
        $baseQuery .= ' AND kq.staff_user_id = ?';
        $params[] = $staffUserId;
        $types .= 'i';
    }

    $groupBy = "
        GROUP BY
            kq.company_id,
            kq.section_id,
            kq.department,
            kq.supervisor_id,
            kq.staff_user_id,
            s.code,
            s.label,
            s.weight,
            ac.id,
            ac.year,
            ac.title,
            c.code,
            c.name,
            sup.first_name,
            sup.last_name,
            sup.email,
            stf.first_name,
            stf.last_name,
            stf.email
    ";

    $having = [];
    $havingParams = [];
    $havingTypes = '';

    if ($search !== '') {
        $having[] = 'SUM(CASE WHEN kq.question_text LIKE ? THEN 1 ELSE 0 END) > 0';
        $havingParams[] = '%' . $search . '%';
        $havingTypes .= 's';
    }

    if ($isActive !== null) {
        if ($isActive === 1) {
            $having[] = 'SUM(CASE WHEN kq.is_active = 1 THEN 1 ELSE 0 END) = COUNT(*)';
        } else {
            $having[] = 'SUM(CASE WHEN kq.is_active = 1 THEN 1 ELSE 0 END) = 0';
        }
    }

    $havingSql = empty($having) ? '' : ' HAVING ' . implode(' AND ', $having);

    // Pagination-independent department options. Keep the current department
    // out of this query so the searchable select always contains every valid
    // department for the remaining cycle/section/scope filters.
    $departmentFilterQuery = "
        FROM kpi_questions kq
        INNER JOIN appraisal_sections s ON s.id = kq.section_id
        WHERE 1=1
    ";
    $departmentFilterParams = [];
    $departmentFilterTypes = '';

    if ($clause['value'] !== null) {
        $departmentFilterQuery .= ' AND kq.company_id = ?';
        $departmentFilterParams[] = $clause['value'];
        $departmentFilterTypes .= 'i';
    }
    if ($sectionId) {
        $departmentFilterQuery .= ' AND kq.section_id = ?';
        $departmentFilterParams[] = $sectionId;
        $departmentFilterTypes .= 'i';
    }
    if ($cycleId) {
        $departmentFilterQuery .= ' AND s.cycle_id = ?';
        $departmentFilterParams[] = $cycleId;
        $departmentFilterTypes .= 'i';
    }
    if ($supervisorId) {
        $departmentFilterQuery .= ' AND kq.supervisor_id = ?';
        $departmentFilterParams[] = $supervisorId;
        $departmentFilterTypes .= 'i';
    }
    if ($staffUserId) {
        $departmentFilterQuery .= ' AND kq.staff_user_id = ?';
        $departmentFilterParams[] = $staffUserId;
        $departmentFilterTypes .= 'i';
    }
    if ($isActive !== null) {
        $departmentFilterQuery .= ' AND kq.is_active = ?';
        $departmentFilterParams[] = $isActive;
        $departmentFilterTypes .= 'i';
    }

    $departmentStmt = $conn->prepare("
        SELECT DISTINCT TRIM(kq.department) AS department
        {$departmentFilterQuery}
        AND kq.department IS NOT NULL
        AND TRIM(kq.department) <> ''
        ORDER BY department ASC
    ");
    if (!$departmentStmt) throw new Exception('Database error: ' . $conn->error, 500);
    if (!empty($departmentFilterParams)) {
        $departmentStmt->bind_param($departmentFilterTypes, ...$departmentFilterParams);
    }
    $departmentStmt->execute();
    $departmentOptions = array_values(array_filter(array_map(
        static fn($row) => $row['department'] ?? null,
        $departmentStmt->get_result()->fetch_all(MYSQLI_ASSOC)
    )));
    $departmentStmt->close();

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
        'department' => 'kq.department',
        'sort_order' => 'sort_order',
        'created_at' => 'created_at',
    ];
    $orderExpression = $sortMap[$sortBy] ?? 'sort_order';

    $dataQuery = "
        SELECT
            MIN(kq.id) AS id,
            MIN(kq.id) AS group_id,
            kq.company_id,
            kq.section_id,
            kq.department,
            kq.supervisor_id,
            kq.staff_user_id,
            MIN(kq.sort_order) AS sort_order,
            MIN(kq.created_at) AS created_at,
            MAX(kq.updated_at) AS updated_at,
            COUNT(*) AS question_count,
            SUM(CASE WHEN kq.is_active = 1 THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN kq.is_active = 0 THEN 1 ELSE 0 END) AS inactive_count,
            SUM(CASE WHEN kq.is_active = 1 AND kq.weight_percent IS NOT NULL THEN 1 ELSE 0 END) AS active_weighted_count,
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
            stf.email AS staff_email,
            CASE
                WHEN kq.staff_user_id IS NOT NULL THEN 'individual'
                WHEN kq.supervisor_id IS NOT NULL THEN 'supervisor'
                ELSE 'department'
            END AS scope,
            CASE
                WHEN SUM(CASE WHEN kq.is_active = 1 THEN 1 ELSE 0 END) = COUNT(*) THEN 'active'
                WHEN SUM(CASE WHEN kq.is_active = 1 THEN 1 ELSE 0 END) = 0 THEN 'inactive'
                ELSE 'mixed'
            END AS group_status,
            CASE
                WHEN SUM(CASE WHEN kq.is_active = 1 AND kq.weight_percent IS NOT NULL THEN 1 ELSE 0 END) = 0 THEN 'equal'
                WHEN SUM(CASE WHEN kq.is_active = 1 AND kq.weight_percent IS NOT NULL THEN 1 ELSE 0 END) = SUM(CASE WHEN kq.is_active = 1 THEN 1 ELSE 0 END) THEN 'custom'
                ELSE 'mixed'
            END AS weight_mode
        {$baseQuery}
        {$groupBy}
        {$havingSql}
        ORDER BY {$orderExpression} {$sortOrder}, kq.department ASC, id ASC
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
        'message' => 'KPI question sets fetched successfully',
        'data' => $data,
        'meta' => [
            'total' => $total,
            'total_questions' => $totalQuestions,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => (int) ceil($total / $limit),
            'sortBy' => $sortBy,
            'sortOrder' => $sortOrder,
            'filter_options' => [
                'departments' => $departmentOptions,
            ],
            'filters' => [
                'section_id' => $sectionId,
                'cycle_id' => $cycleId,
                'department' => $department,
                'supervisor_id' => $supervisorId,
                'staff_user_id' => $staffUserId,
                'is_active' => $isActive,
                'search' => $search ?: null,
                'company_scope' => $companyScope,
            ],
        ],
    ]);
} catch (Exception $e) {
    error_log('GetKpiQuestionGroups Error: ' . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
