<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/../utils/Mailer.php';
require_once __DIR__ . '/../utils/EmailTemplates.php';
require_once __DIR__ . '/../utils/Notifications.php';
require_once __DIR__ . '/AppraisalHelpers.php';

use Dotenv\Dotenv;

header('Content-Type: application/json');

try {
    $dotenv = Dotenv::createImmutable('./');
    $dotenv->safeLoad();

    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') throw new Exception('Bad Request: Only PUT method is allowed', 405);

    $userData = requireRoles(['super_admin', 'admin', 'supervisor']);
    $loggedInUserId = (int)$userData['id'];
    $loggedInRole = $userData['role'] ?? '';
    $loggedInRoleKey = strtolower(str_replace(' ', '_', trim((string)$loggedInRole)));
    $loggedInCompanyId = isset($userData['company_id']) ? (int)$userData['company_id'] : 0;

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception('Invalid request format. Expected JSON object.', 400);

    $appraisalId = isset($data['id']) ? (int)$data['id'] : 0;
    if ($appraisalId <= 0) throw new Exception("Field 'id' is required.", 400);
    $sections = apNormalizeSections($data);
    $notifyStaff = isset($data['notify_staff']) ? (bool)$data['notify_staff'] : (isset($data['send_email']) ? (bool)$data['send_email'] : false);

    $appraisal = apFetchOne($conn, "
        SELECT ap.*, ac.year AS cycle_year, ac.title AS cycle_title, c.name AS company_name, c.code AS company_code
        FROM appraisals ap
        INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id
        INNER JOIN companies c ON c.id = ap.company_id
        WHERE ap.id = {$appraisalId}
        LIMIT 1
    ");
    if (!$appraisal) throw new Exception('Appraisal not found.', 404);

    if ($loggedInRoleKey === 'admin' && (int)$appraisal['company_id'] !== $loggedInCompanyId) {
        throw new Exception('Unauthorized: This appraisal does not belong to your company.', 403);
    }
    if ($loggedInRoleKey === 'admin' && (int) $appraisal['staff_user_id'] !== $loggedInUserId) {
        $adminScope = trim((string) ($userData['staff_scope'] ?? 'All'));
        if (in_array($adminScope, ['Local', 'Expatriate'], true)) {
            $subject = apFetchOne($conn, "SELECT r.name AS role_name, u.staff_type FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE u.id = " . (int) $appraisal['staff_user_id'] . " LIMIT 1");
            if (
                $subject &&
                authRoleKey($subject['role_name'] ?? '') === 'staff' &&
                (string) ($subject['staff_type'] ?? $appraisal['staff_type'] ?? '') !== $adminScope
            ) {
                throw new Exception('Unauthorized: This appraisal is outside your staff scope.', 403);
            }
        }
    }
    $isConductingAppraiser = in_array($loggedInRoleKey, ['admin', 'supervisor'], true)
        && (int) $appraisal['supervisor_id'] === $loggedInUserId;

    if (!$isConductingAppraiser) {
        throw new Exception('Unauthorized: Only the assigned supervisor can update this appraisal.', 403);
    }

    $activeAssignment = apFetchOne($conn, "
        SELECT id
        FROM supervisor_assignments
        WHERE cycle_id = " . (int) $appraisal['cycle_id'] . "
          AND staff_id = " . (int) $appraisal['staff_user_id'] . "
          AND supervisor_id = {$loggedInUserId}
        LIMIT 1
    ");

    if (!$activeAssignment) {
        throw new Exception('This employee is no longer assigned to you for the appraisal cycle.', 403);
    }

    if ($isConductingAppraiser && (int) $appraisal['update_count'] >= 2) {
        throw new Exception('You have reached the maximum number of updates for this appraisal. Please contact an administrator for further changes.', 403);
    }

    $staff = apFetchOne($conn, "SELECT * FROM users WHERE id = " . (int)$appraisal['staff_user_id'] . " LIMIT 1");
    $supervisor = apFetchOne($conn, "SELECT first_name, last_name, fullname, email FROM users WHERE id = " . (int)$appraisal['supervisor_id'] . " LIMIT 1");

    // Every material appraisal update must be acknowledged again by the appraised staff member.
    $status = 'Pending';

    $statusUpgrade = apEsc($conn, $data['status_upgrade'] ?? $appraisal['status_upgrade'] ?? '');
    $salaryUpgrade = apEsc($conn, $data['salary_upgrade'] ?? $appraisal['salary_upgrade'] ?? '');
    $development = apEsc($conn, $data['development'] ?? $appraisal['development'] ?? '');
    $statusSql = apEsc($conn, $status);
    $newUpdateCount = $isConductingAppraiser ? ((int) $appraisal['update_count'] + 1) : (int) $appraisal['update_count'];

    $conn->begin_transaction();
    try {
        $scoreData = apSaveResponsesAndScores($conn, $appraisalId, (int)$appraisal['company_id'], (int)$appraisal['cycle_id'], $sections, [
            'staff_user_id' => (int)$appraisal['staff_user_id'],
            'supervisor_id' => (int)$appraisal['supervisor_id'],
            'logged_in_user_id' => $loggedInUserId,
            'staff_department' => $appraisal['staff_department'] ?? '',
        ]);

        $kpiRatingSql = $scoreData['kpi_rating'] === null ? 'NULL' : (float)$scoreData['kpi_rating'];
        $summarySql = (float)$scoreData['appraisal_summary'];
        $statement = apEsc($conn, $scoreData['evaluation_statement']);
        $snapshot = apEsc($conn, $scoreData['kpi_questions_snapshot']);

        $conn->query("UPDATE appraisals SET
            kpi_questions_snapshot = '{$snapshot}',
            kpi_rating = {$kpiRatingSql},
            appraisal_summary = {$summarySql},
            evaluation_statement = '{$statement}',
            status_upgrade = '{$statusUpgrade}',
            salary_upgrade = '{$salaryUpgrade}',
            development = '{$development}',
            status = '{$statusSql}',
            feedback = NULL,
            acknowledgement = 0,
            acknowledged_at = NULL,
            update_count = {$newUpdateCount},
            updated_by = {$loggedInUserId}
            WHERE id = {$appraisalId}
        ");

        apAudit($conn, (int)$appraisal['company_id'], $loggedInUserId, 'update_appraisal', 'appraisals', $appraisalId, "Updated appraisal for {$appraisal['staff_fullname']} ({$appraisal['cycle_year']})");
        createNotification($conn, (int)$appraisal['company_id'], (int)$appraisal['staff_user_id'], 'appraisal_updated', 'Your appraisal has been updated', "Your {$appraisal['cycle_year']} appraisal was updated. Please review and acknowledge it.", '/appraisals/view/' . $appraisalId);
        if ((int)$appraisal['supervisor_id'] !== (int)$appraisal['staff_user_id']) {
            createNotification($conn, (int)$appraisal['company_id'], (int)$appraisal['supervisor_id'], 'appraisal_updated', 'Appraisal updated', "{$appraisal['staff_fullname']}'s {$appraisal['cycle_year']} appraisal was updated.", '/appraisals/view/' . $appraisalId);
        }
        createNotificationsForCompanyRoles($conn, (int)$appraisal['company_id'], ['admin', 'super_admin'], 'appraisal_updated_admin', 'Appraisal updated', "{$appraisal['staff_fullname']}'s {$appraisal['cycle_year']} appraisal was updated.", '/appraisals/view/' . $appraisalId, [(int)$appraisal['staff_user_id'], (int)$appraisal['supervisor_id']]);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $emailStatus = null;
    if ($notifyStaff) {
        $emailStatus = apSendStaffEmail('update', [
            'staff_email' => $appraisal['staff_email'] ?? ($staff['email'] ?? ''),
            'staff_name' => $appraisal['staff_fullname'],
            'staff_email' => $appraisal['staff_email'] ?? ($staff['email'] ?? ''),
            'cycle_year' => $appraisal['cycle_year'],
            'evaluation_statement' => $scoreData['evaluation_statement'],
            'appraisal_summary' => $scoreData['appraisal_summary'],
            'company_name' => $appraisal['company_name'] ?? 'Lambert Electromec Ltd',
            'company_color' => '#3da050',
            'app_url' => $_ENV['APP_URL'] ?? 'https://lambertelectromec.com.ng',
            'supervisor_name' => $supervisor ? apFullName($supervisor) : 'Your Supervisor',
            'section_scores' => $scoreData['section_scores'],
            'update_number' => $newUpdateCount,
        ]);
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Appraisal updated successfully',
        'data' => ['id' => $appraisalId, 'appraisal_id' => $appraisalId, 'email_status' => $emailStatus, 'staff_notified' => $notifyStaff],
    ]);

} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
