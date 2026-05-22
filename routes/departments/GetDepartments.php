<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 400);
    }

    $userData          = authenticateUser();
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];
    $companyScope      = resolveCompanyScope($userData);
    $clause            = buildCompanyWhereClause($companyScope, 'd');

    $search   = isset($_GET['search']) ? trim($_GET['search']) : (isset($_GET['q']) ? trim($_GET['q']) : '');
    $isActive = isset($_GET['is_active']) && $_GET['is_active'] !== '' ? (int) $_GET['is_active'] : null;
    $companyId = isset($_GET['company_id']) && $_GET['company_id'] !== '' ? (int) $_GET['company_id'] : null;

    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
    $page  = isset($_GET['page'])  ? (int) $_GET['page']  : 1;
    if ($limit <= 0) $limit = 20;
    if ($limit > 500) $limit = 500;
    if ($page <= 0) $page = 1;
    $offset = ($page - 1) * $limit;

    $allowedSort = ['id', 'name', 'is_active', 'created_at', 'updated_at'];
    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSort, true)
        ? $_GET['sortBy']
        : 'name';
    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === 'DESC'
        ? 'DESC'
        : 'ASC';

    $baseQuery = "
        FROM departments d
        INNER JOIN companies c ON c.id = d.company_id
        WHERE 1=1
    ";

    $params = [];
    $types  = '';

    if ($clause['value'] !== null) {
        $baseQuery .= " AND d.company_id = ?";
        $params[] = $clause['value'];
        $types .= 'i';
    } elseif ($loggedInUserRole === 'super_admin' && $companyId) {
        $baseQuery .= " AND d.company_id = ?";
        $params[] = $companyId;
        $types .= 'i';
    }

    if ($isActive !== null) {
        if (!in_array($isActive, [0, 1], true)) {
            throw new Exception("Invalid is_active value. Use 1 or 0.", 400);
        }
        $baseQuery .= " AND d.is_active = ?";
        $params[] = $isActive;
        $types .= 'i';
    }

    if ($search !== '') {
        $baseQuery .= " AND (d.name LIKE ? OR c.name LIKE ? OR c.code LIKE ?)";
        $like = "%{$search}%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'sss';
    }

    $countStmt = $conn->prepare("SELECT COUNT(*) AS total " . $baseQuery);
    if (!$countStmt) throw new Exception("Database error: " . $conn->error, 500);
    if (!empty($params)) $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    $dataQuery = "
        SELECT
            d.id,
            d.company_id,
            d.name,
            d.is_active,
            d.created_at,
            d.updated_at,
            c.code AS company_code,
            c.name AS company_name,
            (
                SELECT COUNT(*)
                FROM users u
                WHERE u.company_id = d.company_id
                  AND u.department = d.name
            ) AS staff_count,
            (
                SELECT COUNT(*)
                FROM kpi_questions kq
                WHERE kq.company_id = d.company_id
                  AND kq.department = d.name
            ) AS question_count
        " . $baseQuery . "
        ORDER BY d.{$sortBy} {$sortOrder}
        LIMIT ? OFFSET ?
    ";

    $dataStmt = $conn->prepare($dataQuery);
    if (!$dataStmt) throw new Exception("Database error: " . $conn->error, 500);

    $dataTypes = $types . 'ii';
    $dataParams = $params;
    $dataParams[] = $limit;
    $dataParams[] = $offset;
    $dataStmt->bind_param($dataTypes, ...$dataParams);
    $dataStmt->execute();
    $departments = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Departments fetched successfully',
        'data' => $departments,
        'meta' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => (int) ceil($total / $limit),
            'sortBy' => $sortBy,
            'sortOrder' => $sortOrder,
            'filters' => [
                'search' => $search ?: null,
                'is_active' => $isActive,
                'company_id' => $companyId,
                'company_scope' => $companyScope,
            ],
        ],
    ]);

} catch (Exception $e) {
    error_log("GetDepartments Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
