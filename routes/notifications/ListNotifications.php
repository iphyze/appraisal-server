<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/../utils/Notifications.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Bad Request: Only GET method is allowed', 405);
    }

    $userData = authenticateUser();
    $userId = (int)($userData['id'] ?? 0);
    ensureNotificationsTable($conn);

    $status = strtolower(trim((string)($_GET['status'] ?? 'all')));
    $status = in_array($status, ['all', 'unread', 'read'], true) ? $status : 'all';
    $page = max((int)($_GET['page'] ?? 1), 1);
    $limit = max(1, min((int)($_GET['limit'] ?? 20), 100));
    $offset = ($page - 1) * $limit;

    $statusWhere = $status === 'unread' ? ' AND is_read = 0' : ($status === 'read' ? ' AND is_read = 1' : '');

    $filteredStmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? {$statusWhere}");
    if (!$filteredStmt) throw new Exception('Database error: ' . $conn->error, 500);
    $filteredStmt->bind_param('i', $userId);
    $filteredStmt->execute();
    $filteredTotal = (int)($filteredStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $filteredStmt->close();

    $totalPages = max((int)ceil($filteredTotal / $limit), 1);
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $limit;
    }

    $stmt = $conn->prepare("SELECT id, type, title, message, link_url, is_read, created_at, read_at FROM notifications WHERE user_id = ? {$statusWhere} ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?");
    if (!$stmt) throw new Exception('Database error: ' . $conn->error, 500);
    $stmt->bind_param('iii', $userId, $limit, $offset);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $summaryStmt = $conn->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END), 0) AS unread, COALESCE(SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END), 0) AS read_count FROM notifications WHERE user_id = ?");
    if (!$summaryStmt) throw new Exception('Database error: ' . $conn->error, 500);
    $summaryStmt->bind_param('i', $userId);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
    $summaryStmt->close();

    echo json_encode([
        'status' => 'Success',
        'message' => 'Notifications fetched successfully',
        'data' => $rows,
        'meta' => [
            'total' => (int)($summary['total'] ?? 0),
            'unread' => (int)($summary['unread'] ?? 0),
            'read' => (int)($summary['read_count'] ?? 0),
            'filtered_total' => $filteredTotal,
            'filter' => $status,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => $totalPages,
        ],
    ]);
} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
