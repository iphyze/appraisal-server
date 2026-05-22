<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/../utils/Notifications.php';

header('Content-Type: application/json; charset=UTF-8');

function notificationReadResponse(string $status, string $message, array $data = [], int $code = 200): void
{
    http_response_code($code);

    echo json_encode([
        'status'  => $status,
        'message' => $message,
        'data'    => $data,
    ]);

    exit;
}

try {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'PATCH'], true)) {
        throw new Exception('Bad Request: Only PUT/PATCH method is allowed.', 405);
    }

    $userData = authenticateUser();
    $userId = (int) ($userData['id'] ?? 0);

    if ($userId <= 0) {
        throw new Exception('Unauthorized: Invalid authenticated user.', 401);
    }

    ensureNotificationsTable($conn);

    $rawBody = file_get_contents('php://input');
    $data = $rawBody !== '' ? json_decode($rawBody, true) : [];

    if ($rawBody !== '' && !is_array($data)) {
        throw new Exception('Invalid request body. Expected valid JSON.', 400);
    }

    $notificationId = isset($data['id']) ? (int) $data['id'] : 0;

    if ($notificationId > 0) {
        $stmt = $conn->prepare("
            UPDATE notifications
            SET is_read = 1,
                read_at = CURRENT_TIMESTAMP
            WHERE id = ?
              AND user_id = ?
              AND is_read = 0
        ");

        if (!$stmt) {
            throw new Exception('Unable to prepare notification update: ' . $conn->error, 500);
        }

        $stmt->bind_param('ii', $notificationId, $userId);
    } else {
        $stmt = $conn->prepare("
            UPDATE notifications
            SET is_read = 1,
                read_at = CURRENT_TIMESTAMP
            WHERE user_id = ?
              AND is_read = 0
        ");

        if (!$stmt) {
            throw new Exception('Unable to prepare notifications update: ' . $conn->error, 500);
        }

        $stmt->bind_param('i', $userId);
    }

    if (!$stmt->execute()) {
        $message = $stmt->error ?: $conn->error;
        $stmt->close();

        throw new Exception('Unable to mark notification as read: ' . $message, 500);
    }

    $affected = (int) $stmt->affected_rows;
    $stmt->close();

    notificationReadResponse(
        'Success',
        $notificationId > 0
            ? 'Notification marked as read successfully.'
            : 'All notifications marked as read successfully.',
        ['affected' => $affected]
    );

} catch (Throwable $e) {
    $code = (int) $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;

    /*
     * While testing locally this will tell you the actual SQL/schema issue.
     * In production, replace the detailed message for code 500 with:
     * 'An internal server error occurred.'
     */
    notificationReadResponse('Failed', $e->getMessage(), [], $code);
}