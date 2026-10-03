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

    $stmt = $conn->prepare("
        SELECT
            COALESCE(MAX(id), 0) AS latest_id,
            COALESCE(SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END), 0) AS unread
        FROM notifications
        WHERE user_id = ?
    ");

    if (!$stmt) {
        throw new Exception('Database error: ' . $conn->error, 500);
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    echo json_encode([
        'status' => 'Success',
        'message' => 'Notification state fetched successfully',
        'data' => [
            'latest_id' => (int)($row['latest_id'] ?? 0),
            'unread' => (int)($row['unread'] ?? 0),
        ],
    ]);
} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
