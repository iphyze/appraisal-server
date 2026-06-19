<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/../utils/Mailer.php';
require_once __DIR__ . '/../utils/EmailTemplates.php';
require_once __DIR__ . '/../utils/MailRecipients.php';
require_once __DIR__ . '/../utils/Notifications.php';

header('Content-Type: application/json');

function cleanEmailList($items): array
{
    $valid = [];
    foreach ((array)$items as $item) {
        $email = strtolower(trim((string)$item));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $valid[$email] = $email;
    }
    return array_values($valid);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Bad Request: Only POST method is allowed', 405);
    $userData = requireRoles(['super_admin', 'admin']);
    $loggedInUserId = (int)$userData['id'];
    $loggedInCompanyId = (int)($userData['company_id'] ?? 0);
    $senderName = trim(($userData['first_name'] ?? '') . ' ' . ($userData['last_name'] ?? '')) ?: 'Administrator';

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception('Invalid request format.', 400);

    $mode = trim((string)($data['mode'] ?? 'custom_emails'));
    $cycleId = (int)($data['cycle_id'] ?? 0);
    $subject = trim((string)($data['subject'] ?? ''));
    $customMessage = trim((string)($data['custom_message'] ?? ''));

    $rawCc = $data['cc_emails'] ?? [];
    if (is_string($rawCc)) {
        $rawCc = explode(',', $rawCc);
    }

    $ccCandidates = array_values(array_filter(
        array_map(static fn($item) => trim((string) $item), (array) $rawCc),
        static fn($item) => $item !== ''
    ));

    foreach ($ccCandidates as $ccAddress) {
        if (!filter_var($ccAddress, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid CC email address: {$ccAddress}", 400);
        }
    }

    $ccEmails = cleanEmailList($ccCandidates);
    if (count($ccEmails) > 20) {
        throw new Exception('A maximum of 20 unique CC addresses is allowed.', 400);
    }

    if ($subject === '' || $customMessage === '') throw new Exception('Subject and message are required.', 400);

    $cycle = $cycleId > 0 ? resolveMailCycle($conn, $userData, $cycleId) : null;
    $recipients = [];

    if ($mode === 'custom_emails') {
        $emails = cleanEmailList($data['emails'] ?? []);
        if (!$emails) throw new Exception('Enter at least one valid email address.', 400);
        foreach ($emails as $email) {
            $recipients[] = ['recipient_id' => 0, 'email' => $email, 'full_name' => $email, 'role' => 'external', 'is_portal_user' => false];
        }
    } else {
        $allowed = ['pending_acknowledgements', 'pending_supervisors', 'cycle_supervisors', 'specific_users'];
        if (!in_array($mode, $allowed, true)) throw new Exception('Unsupported recipient type.', 400);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($data['recipient_ids'] ?? [])))));
        if (!$ids) throw new Exception('Select at least one recipient.', 400);
        $available = fetchMailRecipients($conn, $userData, $mode, $cycleId, '');
        $recipients = array_values(array_filter($available['rows'], fn($row) => in_array((int)$row['recipient_id'], $ids, true)));
        if (!$recipients) throw new Exception('No eligible recipients were found for this communication.', 404);
        foreach ($recipients as &$recipient) $recipient['is_portal_user'] = true;
        unset($recipient);
        $cycle = $available['cycle'] ?: $cycle;
    }

    $cycleYear = (string)($cycle['year'] ?? date('Y'));
    $companyName = (string)($cycle['company_name'] ?? ($userData['company_name'] ?? 'Lambert Electromec Ltd'));
    $targetCompanyId = (int)($cycle['company_id'] ?? $loggedInCompanyId);
    $appUrl = $_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'https://lambertelectromec.com.ng';
    $results = ['sent' => [], 'failed' => []];

    foreach ($recipients as $recipient) {
        $email = trim((string)($recipient['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $recipientName = trim((string)($recipient['full_name'] ?? '')) ?: $email;
        $html = getManualNotificationEmail([
            'recipient_name' => $recipientName,
            'recipient_type' => mailRoleKey($recipient['role'] ?? 'external'),
            'recipient_email' => $email,
            'is_portal_user' => !empty($recipient['is_portal_user']),
            'custom_message' => $customMessage,
            'cycle_year' => $cycleYear,
            'company_name' => $companyName,
            'app_url' => $appUrl,
            'sender_name' => $senderName,
        ]);
        $sent = sendMail($email, $subject, $html, $companyName, $ccEmails);
        if ($sent === true) {
            $results['sent'][] = ['name' => $recipientName, 'email' => $email];
            if (!empty($recipient['recipient_id'])) {
                createNotification($conn, $targetCompanyId, (int)$recipient['recipient_id'], 'manual_message', $subject, $customMessage, '/notifications');
            }
        } else {
            $results['failed'][] = ['name' => $recipientName, 'email' => $email, 'reason' => $sent];
        }
    }

    $sentCount = count($results['sent']);
    $failedCount = count($results['failed']);
    $ccCount = count($ccEmails);
    $description = "{$senderName} sent appraisal communication ({$mode}). Sent: {$sentCount}; Failed: {$failedCount}; CC: {$ccCount}. Subject: {$subject}";
    $log = $conn->prepare('INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description) VALUES (?, ?, ?, ?, ?, ?)');
    if ($log) {
        $action = 'send_manual_email'; $table = 'users'; $targetId = 0;
        $log->bind_param('iissis', $targetCompanyId, $loggedInUserId, $action, $table, $targetId, $description);
        $log->execute(); $log->close();
    }

    echo json_encode([
        'status' => 'Success',
        'message' => "Email operation complete. Sent: {$sentCount}, Failed: {$failedCount}.",
        'data' => [
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'cc_count' => $ccCount,
            'cc_emails' => $ccEmails,
            'sent' => $results['sent'],
            'failed' => $results['failed'],
        ],
    ]);
} catch (Exception $e) {
    $code = (int)$e->getCode(); $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
