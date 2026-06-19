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

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Bad Request: Only POST method is allowed', 405);

    $userData = requireRoles(['super_admin', 'admin', 'supervisor']);
    $loggedInUserId = (int)$userData['id'];
    $loggedInRole = $userData['role'] ?? '';
    $loggedInRoleKey = strtolower(str_replace(' ', '_', trim((string)$loggedInRole)));
    $loggedInCompanyId = isset($userData['company_id']) ? (int)$userData['company_id'] : 0;

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception('Invalid request format. Expected JSON object.', 400);

    $staffUserId = isset($data['staff_user_id']) ? (int)$data['staff_user_id'] : 0;
    $cycleId = isset($data['cycle_id']) ? (int)$data['cycle_id'] : 0;
    $sections = apNormalizeSections($data);
    $notifyStaff = isset($data['notify_staff']) ? (bool)$data['notify_staff'] : (isset($data['send_email']) ? (bool)$data['send_email'] : true);

    if ($staffUserId <= 0) throw new Exception("Field 'staff_user_id' is required.", 400);
    if ($cycleId <= 0) throw new Exception("Field 'cycle_id' is required.", 400);

    $companyScope = resolveCompanyScope($userData);
    $cycleScope = $companyScope !== null ? " AND company_id = " . (int) $companyScope : '';
    $cycle = apFetchOne($conn, "SELECT * FROM appraisal_cycles WHERE id = {$cycleId} {$cycleScope} LIMIT 1");
    if (!$cycle) throw new Exception('Selected appraisal cycle was not found or is outside your company scope.', 404);
    $companyId = (int)$cycle['company_id'];

    $staff = apFetchOne($conn, "
        SELECT u.*, r.name AS role_name
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = {$staffUserId}
          AND u.company_id = {$companyId}
          AND u.is_active = 1
          AND " . appraiseeRoleWhere('r') . "
        LIMIT 1
    ");
    if (!$staff) throw new Exception('Staff member was not found for this appraisal cycle.', 404);

    // An administrator's Local/Expatriate scope restricts regular staff only.
    // Peer administrators and supervisors remain valid appraisal subjects.
    if ($loggedInRoleKey === 'admin') {
        $adminScope = trim((string) ($userData['staff_scope'] ?? 'All'));
        if (
            in_array($adminScope, ['Local', 'Expatriate'], true) &&
            authRoleKey($staff['role_name'] ?? '') === 'staff' &&
            (string) ($staff['staff_type'] ?? '') !== $adminScope
        ) {
            throw new Exception('Unauthorized: This employee is outside your staff scope.', 403);
        }
    }

    if (!in_array($loggedInRoleKey, ['admin', 'supervisor'], true)) {
        throw new Exception('Super administrators may view appraisals, but only the assigned supervisor can start one.', 403);
    }

    $assignment = apFetchOne($conn, "
        SELECT id, supervisor_id
        FROM supervisor_assignments
        WHERE cycle_id = {$cycleId}
          AND staff_id = {$staffUserId}
        ORDER BY id ASC
        LIMIT 1
    ");

    if (!$assignment) {
        throw new Exception('Assign this employee to a supervisor before starting the appraisal.', 400);
    }

    $supervisorId = (int) $assignment['supervisor_id'];
    if ($supervisorId !== $loggedInUserId) {
        throw new Exception('This employee is not assigned to you for this appraisal cycle.', 403);
    }

    $onboard = apFetchOne($conn, "SELECT id FROM supervisor_onboarding WHERE cycle_id = {$cycleId} AND supervisor_id = {$supervisorId} LIMIT 1");
    if (!$onboard) throw new Exception('Complete supervisor onboarding before starting this appraisal.', 403);

    $duplicate = apFetchOne($conn, "SELECT id FROM appraisals WHERE staff_user_id = {$staffUserId} AND cycle_id = {$cycleId} LIMIT 1");
    if ($duplicate) throw new Exception('This staff member already has an appraisal for the selected cycle.', 400);

    $company = apFetchOne($conn, "SELECT name, code FROM companies WHERE id = {$companyId} LIMIT 1");
    $supervisor = apFetchOne($conn, "SELECT first_name, last_name, fullname, email FROM users WHERE id = {$supervisorId} LIMIT 1");

    $staffFullname = apEsc($conn, apFullName($staff));
    $staffDepartment = apEsc($conn, $staff['department'] ?? '');
    $staffLocation = apEsc($conn, $staff['location'] ?? '');
    $staffJobTitle = apEsc($conn, $staff['job_title'] ?? '');
    $staffType = apEsc($conn, $staff['staff_type'] ?? '');
    $staffEmail = apEsc($conn, $staff['email'] ?? '');
    $dateOfJoining = !empty($staff['date_of_joining']) ? "'" . apEsc($conn, $staff['date_of_joining']) . "'" : 'NULL';
    $durationYears = apDurationYears($staff['date_of_joining'] ?? null);
    $durationSql = $durationYears === null ? 'NULL' : (float)$durationYears;
    $statusUpgrade = apEsc($conn, $data['status_upgrade'] ?? '');
    $salaryUpgrade = apEsc($conn, $data['salary_upgrade'] ?? '');
    $development = apEsc($conn, $data['development'] ?? '');

    $conn->begin_transaction();
    try {
        $conn->query("INSERT INTO appraisals (
            company_id, cycle_id, staff_user_id, supervisor_id,
            staff_fullname, staff_department, staff_location, staff_job_title, staff_type, staff_email,
            date_of_joining, duration_years, status_upgrade, salary_upgrade, development,
            status, created_by, updated_by
        ) VALUES (
            {$companyId}, {$cycleId}, {$staffUserId}, {$supervisorId},
            '{$staffFullname}', '{$staffDepartment}', '{$staffLocation}', '{$staffJobTitle}', '{$staffType}', '{$staffEmail}',
            {$dateOfJoining}, {$durationSql}, '{$statusUpgrade}', '{$salaryUpgrade}', '{$development}',
            'Pending', {$loggedInUserId}, {$loggedInUserId}
        )");

        $appraisalId = (int)$conn->insert_id;
        $scoreData = apSaveResponsesAndScores($conn, $appraisalId, $companyId, $cycleId, $sections, [
            'staff_user_id' => $staffUserId,
            'supervisor_id' => $supervisorId,
            'logged_in_user_id' => $loggedInUserId,
            'staff_department' => $staff['department'] ?? '',
        ]);

        $kpiRatingSql = $scoreData['kpi_rating'] === null ? 'NULL' : (float)$scoreData['kpi_rating'];
        $summarySql = (float)$scoreData['appraisal_summary'];
        $statement = apEsc($conn, $scoreData['evaluation_statement']);
        $snapshot = apEsc($conn, $scoreData['kpi_questions_snapshot']);

        $conn->query("UPDATE appraisals SET
            kpi_questions_snapshot = '{$snapshot}',
            kpi_rating = {$kpiRatingSql},
            appraisal_summary = {$summarySql},
            evaluation_statement = '{$statement}'
            WHERE id = {$appraisalId}
        ");

        apAudit($conn, $companyId, $loggedInUserId, 'create_appraisal', 'appraisals', $appraisalId, "Created appraisal for {$staffFullname} ({$cycle['year']})");
        createNotification($conn, $companyId, $staffUserId, 'appraisal_submitted', 'Your appraisal has been submitted', "Your {$cycle['year']} appraisal has been submitted and is ready for your acknowledgement.", '/appraisals/view/' . $appraisalId);
        if ($supervisorId && $supervisorId !== $staffUserId) {
            createNotification($conn, $companyId, $supervisorId, 'appraisal_submitted', 'Appraisal submitted', "An appraisal for {$staffFullname} has been submitted for the {$cycle['year']} cycle.", '/appraisals/view/' . $appraisalId);
        }
        createNotificationsForCompanyRoles($conn, $companyId, ['admin', 'super_admin'], 'appraisal_submitted_admin', 'New appraisal submitted', "{$staffFullname}'s {$cycle['year']} appraisal has been submitted.", '/appraisals/view/' . $appraisalId, [$staffUserId, $supervisorId]);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $emailStatus = null;
    if ($notifyStaff) {
        $emailStatus = apSendStaffEmail('create', [
            'staff_email' => $staff['email'] ?? '',
            'staff_name' => apFullName($staff),
            'cycle_year' => $cycle['year'],
            'evaluation_statement' => $scoreData['evaluation_statement'],
            'appraisal_summary' => $scoreData['appraisal_summary'],
            'unique_ref' => $staff['unique_ref'] ?? '',
            'company_name' => $company['name'] ?? 'Lambert Electromec Ltd',
            'company_color' => '#3da050',
            'app_url' => $_ENV['APP_URL'] ?? 'https://lambertelectromec.com.ng',
            'supervisor_name' => $supervisor ? apFullName($supervisor) : 'Your Supervisor',
            'section_scores' => $scoreData['section_scores'],
        ]);
    }

    http_response_code(201);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Appraisal created successfully',
        'data' => ['id' => $appraisalId, 'appraisal_id' => $appraisalId, 'email_status' => $emailStatus],
    ]);

} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
