<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/../utils/Mailer.php';
require_once __DIR__ . '/../utils/EmailTemplates.php';
require_once __DIR__ . '/../utils/Notifications.php';

use Dotenv\Dotenv;

header('Content-Type: application/json');

try {
    $dotenv = Dotenv::createImmutable('./');
    $dotenv->safeLoad();

    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception('Bad Request: Only PUT method is allowed', 405);
    }

    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];
    $loggedInRole = strtolower(str_replace(' ', '_', trim((string)($userData['role'] ?? ''))));
    $loggedInCompanyId = (int)($userData['company_id'] ?? 0);

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception('Invalid request format. Expected JSON object.', 400);

    $appraisalId = isset($data['id']) ? (int)$data['id'] : 0;
    $feedbackText = trim((string)($data['feedback'] ?? ''));
    $acknowledged = !empty($data['acknowledged']);

    if ($appraisalId <= 0) throw new Exception("Field 'id' is required.", 400);
    if (!$acknowledged) throw new Exception('Please acknowledge that you have seen and confirmed this appraisal before submitting feedback.', 400);
    if ($feedbackText === '') throw new Exception("Field 'feedback' is required.", 400);

    $fetchStmt = $conn->prepare("\n        SELECT ap.id, ap.staff_user_id, ap.supervisor_id, ap.company_id, ap.status, ap.edited_count,\n               ap.staff_fullname, ap.appraisal_summary, ap.evaluation_statement, ap.staff_email,\n               ac.year AS cycle_year, c.name AS company_name,\n               CONCAT(sup.first_name, ' ', sup.last_name) AS supervisor_name,\n               sup.email AS supervisor_email\n        FROM appraisals ap\n        INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id\n        INNER JOIN companies c ON c.id = ap.company_id\n        LEFT JOIN users sup ON sup.id = ap.supervisor_id\n        WHERE ap.id = ?\n        LIMIT 1\n    ");
    if (!$fetchStmt) throw new Exception('Database error: ' . $conn->error, 500);
    $fetchStmt->bind_param('i', $appraisalId);
    $fetchStmt->execute();
    $appraisal = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    if (!$appraisal) throw new Exception('Appraisal not found.', 404);

    // Acknowledgement is always personal: staff, supervisors and administrators may
    // acknowledge only an appraisal in which they are the appraised employee.
    // Super administrators are governance-only and are never appraisal subjects.
    if ($loggedInRole === 'super_admin' || !in_array($loggedInRole, ['staff', 'supervisor', 'admin'], true)) {
        throw new Exception('Unauthorized: This role cannot acknowledge appraisals.', 403);
    }
    if ((int) $appraisal['staff_user_id'] !== $loggedInUserId) {
        throw new Exception('Unauthorized: You can only submit feedback on your own appraisal.', 403);
    }
    if ($loggedInRole === 'admin' && (int) $appraisal['company_id'] !== $loggedInCompanyId) {
        throw new Exception('Unauthorized: This appraisal does not belong to your company.', 403);
    }
    if (($appraisal['status'] ?? '') === 'Acknowledged') {
        throw new Exception('This appraisal has already been acknowledged.', 400);
    }

    $newEditCount = (int)$appraisal['edited_count'] + 1;
    $updateStmt = $conn->prepare("\n        UPDATE appraisals\n        SET feedback = ?, status = 'Acknowledged', acknowledgement = 1, acknowledged_at = NOW(), edited_count = ?, updated_by = ?\n        WHERE id = ?\n    ");
    if (!$updateStmt) throw new Exception('Database error: ' . $conn->error, 500);
    $updateStmt->bind_param('siii', $feedbackText, $newEditCount, $loggedInUserId, $appraisalId);
    if (!$updateStmt->execute()) throw new Exception('Feedback submission failed: ' . $updateStmt->error, 500);
    $updateStmt->close();

    $logStmt = $conn->prepare("\n        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)\n        VALUES (?, ?, ?, ?, ?, ?)\n    ");
    if ($logStmt) {
        $action = 'submit_feedback';
        $targetTable = 'appraisals';
        $description = "{$appraisal['staff_fullname']} submitted feedback on their {$appraisal['cycle_year']} appraisal";
        $logStmt->bind_param('iissis', $appraisal['company_id'], $loggedInUserId, $action, $targetTable, $appraisalId, $description);
        $logStmt->execute();
        $logStmt->close();
    }

    createNotification($conn, (int)$appraisal['company_id'], (int)$appraisal['supervisor_id'], 'feedback_submitted', 'Staff acknowledged an appraisal', "{$appraisal['staff_fullname']} has acknowledged and submitted feedback on their {$appraisal['cycle_year']} appraisal.", '/appraisals/view/' . $appraisalId);

    $emailStatus = null;
    if (!empty($appraisal['supervisor_email'])) {
        $emailHtml = getFeedbackSubmittedEmail([
            'supervisor_name' => $appraisal['supervisor_name'],
            'staff_name' => $appraisal['staff_fullname'],
            'cycle_year' => $appraisal['cycle_year'],
            'feedback' => $feedbackText,
            'appraisal_summary' => $appraisal['appraisal_summary'],
            'evaluation_statement' => $appraisal['evaluation_statement'],
            'company_name' => $appraisal['company_name'],
            'company_color' => '#3da050',
            'app_url' => $_ENV['APP_URL'] ?? 'https://lambertelectromec.com.ng',
        ]);

        $mailResult = sendMail(
            $appraisal['supervisor_email'],
            "{$appraisal['staff_fullname']} Has Submitted Appraisal Feedback — {$appraisal['company_name']}",
            $emailHtml,
            $appraisal['company_name']
        );
        $emailStatus = $mailResult === true ? 'sent' : 'failed: ' . $mailResult;
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Feedback submitted successfully',
        'data' => ['appraisal_id' => $appraisalId, 'email_status' => $emailStatus],
    ]);

} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
