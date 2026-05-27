<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/../utils/Mailer.php';
require_once __DIR__ . '/../utils/EmailTemplates.php';
// EmailTemplates already loaded

use Dotenv\Dotenv;

header('Content-Type: application/json');

try {
    $dotenv = Dotenv::createImmutable('./');
    $dotenv->load();

    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Bad Request: Only PUT method is allowed", 400);
    }

    $userData          = authenticateUser();
    $loggedInUserId    = (int) $userData['id'];
    $loggedInRole      = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) throw new Exception("Invalid request format.", 400);

    if (!isset($data['id']) || !is_numeric($data['id'])) {
        throw new Exception("Field 'id' is required.", 400);
    }

    $appraisalId = (int) $data['id'];

    // Fetch appraisal + supervisor email for notification
    $fetchStmt = $conn->prepare("
        SELECT ap.id, ap.staff_user_id, ap.supervisor_id, ap.company_id,
               ap.status, ap.edited_count, ap.staff_fullname,
               ap.appraisal_summary, ap.evaluation_statement,
               ap.staff_email,
               ac.year AS cycle_year,
               c.name  AS company_name,
               CONCAT(sup.first_name, ' ', sup.last_name) AS supervisor_name,
               sup.email AS supervisor_email
        FROM appraisals ap
        INNER JOIN appraisal_cycles ac ON ac.id  = ap.cycle_id
        INNER JOIN companies c         ON c.id   = ap.company_id
        LEFT  JOIN users sup           ON sup.id = ap.supervisor_id
        WHERE ap.id = ? LIMIT 1
    ");
    $fetchStmt->bind_param("i", $appraisalId);
    $fetchStmt->execute();
    $appraisal = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    if (!$appraisal) throw new Exception("Appraisal not found.", 404);

    if (
        $loggedInRole === 'admin' &&
        (int) $appraisal['company_id'] !== $loggedInCompanyId
    ) {
        throw new Exception("Unauthorized: This appraisal does not belong to your company.", 403);
    }

    $updateFields = [];
    $params       = [];
    $types        = "";
    $feedbackText = null;

    // ── Staff submitting feedback ──────────────────────────────────────────────
    if ($loggedInRole === 'staff') {
        if ((int) $appraisal['staff_user_id'] !== $loggedInUserId) {
            throw new Exception("Unauthorized: You can only submit feedback on your own appraisal.", 403);
        }
        if ($appraisal['status'] === 'Confirmed') {
            throw new Exception("This appraisal has been confirmed and can no longer be edited.", 400);
        }
        if (!isset($data['feedback']) || trim($data['feedback']) === '') {
            throw new Exception("Field 'feedback' is required.", 400);
        }

        $feedbackText   = trim($data['feedback']);
        $newEditCount   = (int) $appraisal['edited_count'] + 1;
        $updateFields[] = "feedback = ?";
        $updateFields[] = "edited_count = ?";
        $params[]       = $feedbackText;
        $params[]       = $newEditCount;
        $types         .= "si";
    }

    // ── Admin/supervisor updating status or recommendations ────────────────────
    if (in_array($loggedInRole, ['super_admin', 'admin', 'supervisor'])) {

        if ($loggedInRole === 'supervisor' && (int) $appraisal['supervisor_id'] !== $loggedInUserId) {
            throw new Exception("Unauthorized: You can only update appraisals you conducted.", 403);
        }

        if (isset($data['status'])) {
            if (!in_array($data['status'], ['Pending', 'Confirmed', 'Rejected'])) {
                throw new Exception("Invalid status. Allowed: Pending, Confirmed, Rejected.", 400);
            }
            $updateFields[] = "status = ?";
            $params[]       = $data['status'];
            $types         .= "s";
        }

        if (isset($data['status_upgrade'])) {
            $updateFields[] = "status_upgrade = ?";
            $params[]       = trim($data['status_upgrade']);
            $types         .= "s";
        }
        if (isset($data['salary_upgrade'])) {
            $updateFields[] = "salary_upgrade = ?";
            $params[]       = trim($data['salary_upgrade']);
            $types         .= "s";
        }
        if (isset($data['development'])) {
            $updateFields[] = "development = ?";
            $params[]       = trim($data['development']);
            $types         .= "s";
        }
    }

    if (empty($updateFields)) throw new Exception("No valid fields provided for update.", 400);

    $updateFields[] = "updated_by = ?";
    $params[]       = $loggedInUserId;
    $types         .= "i";

    $sql      = "UPDATE appraisals SET " . implode(", ", $updateFields) . " WHERE id = ?";
    $params[] = $appraisalId;
    $types   .= "i";

    $updateStmt = $conn->prepare($sql);
    if (!$updateStmt) throw new Exception("Database error: " . $conn->error, 500);
    $updateStmt->bind_param($types, ...$params);
    if (!$updateStmt->execute()) throw new Exception("Update failed: " . $updateStmt->error, 500);
    $updateStmt->close();

    // Log
    $logStmt = $conn->prepare("
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?,?,?,?,?,?)
    ");
    if ($logStmt) {
        $action      = $loggedInRole === 'staff' ? "submit_feedback" : "update_appraisal_status";
        $targetTable = "appraisals";
        $description = $loggedInRole === 'staff'
            ? "{$appraisal['staff_fullname']} submitted feedback on their {$appraisal['cycle_year']} appraisal"
            : "Appraisal ID {$appraisalId} status updated to " . ($data['status'] ?? 'N/A');
        $logStmt->bind_param("iissis", $appraisal['company_id'], $loggedInUserId, $action, $targetTable, $appraisalId, $description);
        $logStmt->execute();
        $logStmt->close();
    }

    // ── Email supervisor when staff submits feedback ───────────────────────────
    $emailStatus = null;
    if ($loggedInRole === 'staff' && $feedbackText && !empty($appraisal['supervisor_email'])) {
        $emailHtml = getFeedbackSubmittedEmail([
            'supervisor_name'      => $appraisal['supervisor_name'],
            'staff_name'           => $appraisal['staff_fullname'],
            'cycle_year'           => $appraisal['cycle_year'],
            'feedback'             => $feedbackText,
            'appraisal_summary'    => $appraisal['appraisal_summary'],
            'evaluation_statement' => $appraisal['evaluation_statement'],
            'company_name'         => $appraisal['company_name'],
            'company_color'        => '#1a3c5e',
            'app_url'              => $_ENV['APP_URL'] ?? 'https://lambertelectromec.com.ng',
        ]);

        $mailResult  = sendMail(
            $appraisal['supervisor_email'],
            "{$appraisal['staff_fullname']} Has Submitted Appraisal Feedback — {$appraisal['company_name']}",
            $emailHtml,
            $appraisal['company_name']
        );
        $emailStatus = $mailResult === true ? 'sent' : 'failed: ' . $mailResult;
    }

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => $loggedInRole === 'staff'
            ? "Feedback submitted successfully"
            : "Appraisal updated successfully",
        "data"    => [
            "appraisal_id" => $appraisalId,
            "email_status" => $emailStatus,
        ]
    ]);

} catch (Exception $e) {
    error_log("SubmitFeedback Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}