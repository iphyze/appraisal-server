<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Bad Request: Only GET method is allowed', 405);
    $userData = requireRoles(['super_admin', 'admin']);
    $companyScope = resolveCompanyScope($userData);
    $page = isset($_GET['page']) ? max((int)$_GET['page'], 1) : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $limit = max(10, min($limit, 100));
    $offset = ($page - 1) * $limit;
    $search = trim((string)($_GET['search'] ?? ''));

    $where = [];
    if ($companyScope !== null) $where[] = "a.company_id = " . (int) $companyScope;
    if ($search !== '') {
        $safe = $conn->real_escape_string($search);
        $where[] = "(a.action LIKE '%{$safe}%' OR a.description LIKE '%{$safe}%' OR u.email LIKE '%{$safe}%')";
    }
    $whereSql = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $count = $conn->query("SELECT COUNT(*) AS total FROM audit_log a LEFT JOIN users u ON u.id = a.user_id {$whereSql}");
    if (!$count) throw new Exception('Database error: ' . $conn->error, 500);
    $total = (int)($count->fetch_assoc()['total'] ?? 0);
    $totalPages = max((int)ceil($total / $limit), 1);

    $sql = "SELECT a.id, a.company_id, c.name AS company_name, a.user_id, TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS user_name, u.email AS user_email, a.action, a.target_table, a.target_id, a.description, a.ip_address, a.created_at FROM audit_log a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN companies c ON c.id = a.company_id {$whereSql} ORDER BY a.created_at DESC, a.id DESC LIMIT {$limit} OFFSET {$offset}";
    $res = $conn->query($sql);
    if (!$res) throw new Exception('Database error: ' . $conn->error, 500);
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;

    echo json_encode(['status' => 'Success', 'message' => 'Audit log fetched successfully', 'data' => $rows, 'meta' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'total_pages' => $totalPages]]);
} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
