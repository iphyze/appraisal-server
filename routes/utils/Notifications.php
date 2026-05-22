<?php

function ensureNotificationsTable(mysqli $conn): void
{
    $createSql = "
        CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT UNSIGNED DEFAULT NULL,
            user_id INT UNSIGNED NOT NULL,
            type VARCHAR(80) NOT NULL DEFAULT 'info',
            title VARCHAR(180) NOT NULL,
            message TEXT DEFAULT NULL,
            link_url VARCHAR(500) DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_notifications_user (user_id, is_read, created_at),
            KEY idx_notifications_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";

    if (!$conn->query($createSql)) {
        throw new Exception('Unable to initialise notifications table: ' . $conn->error, 500);
    }
}

function createNotification(
    mysqli $conn,
    $companyId,
    $userId,
    string $type,
    string $title,
    string $message = '',
    string $linkUrl = ''
): bool {
    $userId = (int) $userId;

    if ($userId <= 0) {
        return false;
    }

    ensureNotificationsTable($conn);

    $companyId = $companyId ? (int) $companyId : null;

    $stmt = $conn->prepare("
        INSERT INTO notifications (
            company_id,
            user_id,
            type,
            title,
            message,
            link_url
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        throw new Exception('Unable to prepare notification insert: ' . $conn->error, 500);
    }

    $stmt->bind_param(
        'iissss',
        $companyId,
        $userId,
        $type,
        $title,
        $message,
        $linkUrl
    );

    if (!$stmt->execute()) {
        $error = $stmt->error ?: $conn->error;
        $stmt->close();

        throw new Exception('Unable to create notification: ' . $error, 500);
    }

    $stmt->close();

    return true;
}

function createNotificationsForCompanyRoles(
    mysqli $conn,
    $companyId,
    array $roles,
    string $type,
    string $title,
    string $message = '',
    string $linkUrl = '',
    array $excludeUserIds = []
): bool {
    ensureNotificationsTable($conn);

    $companyId = (int) $companyId;

    if ($companyId <= 0 || empty($roles)) {
        return false;
    }

    $normalisedRoles = array_values(array_unique(array_map(
        function ($role) {
            return strtolower(str_replace(' ', '_', trim((string) $role)));
        },
        $roles
    )));

    $rolePlaceholders = implode(',', array_fill(0, count($normalisedRoles), '?'));
    $types = 'i' . str_repeat('s', count($normalisedRoles));
    $params = array_merge([$companyId], $normalisedRoles);

    $excludeSql = '';

    if (!empty($excludeUserIds)) {
        $excludeUserIds = array_values(array_filter(array_map('intval', $excludeUserIds)));
    }

    if (!empty($excludeUserIds)) {
        $excludePlaceholders = implode(',', array_fill(0, count($excludeUserIds), '?'));
        $excludeSql = " AND u.id NOT IN ({$excludePlaceholders})";
        $types .= str_repeat('i', count($excludeUserIds));
        $params = array_merge($params, $excludeUserIds);
    }

    $stmt = $conn->prepare("
        SELECT u.id
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.company_id = ?
          AND u.is_active = 1
          AND LOWER(REPLACE(TRIM(r.name), ' ', '_')) IN ({$rolePlaceholders})
          {$excludeSql}
    ");

    if (!$stmt) {
        throw new Exception('Unable to prepare notification recipient lookup: ' . $conn->error, 500);
    }

    $stmt->bind_param($types, ...$params);

    if (!$stmt->execute()) {
        $error = $stmt->error ?: $conn->error;
        $stmt->close();

        throw new Exception('Unable to load notification recipients: ' . $error, 500);
    }

    $result = $stmt->get_result();
    $recipientIds = [];

    while ($row = $result->fetch_assoc()) {
        $recipientIds[] = (int) $row['id'];
    }

    $stmt->close();

    foreach ($recipientIds as $recipientId) {
        createNotification(
            $conn,
            $companyId,
            $recipientId,
            $type,
            $title,
            $message,
            $linkUrl
        );
    }

    return true;
}