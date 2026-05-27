<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 400);
    }

    $userData = authenticateUser();

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("Missing required parameter: 'id'.", 400);
    }

    $id      = (int) $_GET['id'];
    $cycleId = isset($_GET['cycle_id']) ? (int) $_GET['cycle_id'] : null;

    // Fetch supervisor profile
    $stmt = $conn->prepare("
        SELECT
            u.id, u.staff_id, u.first_name, u.last_name, u.email,
            u.username, u.department, u.job_title, u.staff_type,
            u.location, u.date_of_joining, u.is_active,
            u.onboarded_at, u.last_login_at, u.created_at,
            r.name  AS role,
            c.id    AS company_id,
            c.code  AS company_code,
            c.name  AS company_name
        FROM users u
        INNER JOIN roles r     ON r.id = u.role_id
        INNER JOIN companies c ON c.id = u.company_id
        WHERE u.id = ? AND r.name = 'supervisor'
        LIMIT 1
    ");
    if (!$stmt) throw new Exception("Database error: " . $conn->error, 500);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $supervisor = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$supervisor) throw new Exception("Supervisor not found.", 404);

    // Company check
    if (
        $userData['role'] !== 'super_admin' &&
        (int) $supervisor['company_id'] !== (int) $userData['company_id']
    ) {
        throw new Exception("Unauthorized: You can only view supervisors within your company.", 403);
    }

    // Resolve cycle — use provided cycle_id or fall back to active cycle
    if ($cycleId) {
        $cycleStmt = $conn->prepare("SELECT id, year, title FROM appraisal_cycles WHERE id = ? LIMIT 1");
        $cycleStmt->bind_param("i", $cycleId);
    } else {
        $cycleStmt = $conn->prepare("
            SELECT id, year, title FROM appraisal_cycles
            WHERE company_id = ? AND is_active = 1 LIMIT 1
        ");
        $cycleStmt->bind_param("i", $supervisor['company_id']);
    }
    $cycleStmt->execute();
    $cycle = $cycleStmt->get_result()->fetch_assoc();
    $cycleStmt->close();

    // Subordinate stats for the resolved cycle
    $subordinateStats = ['total' => 0, 'appraised' => 0, 'pending' => 0, 'progress' => 0];
    $onboardingStatus = null;

    if ($cycle) {
        $statsStmt = $conn->prepare("
            SELECT
                COUNT(DISTINCT sa.staff_id)      AS total,
                COUNT(DISTINCT ap.staff_user_id) AS appraised
            FROM supervisor_assignments sa
            LEFT JOIN appraisals ap ON ap.staff_user_id = sa.staff_id
                                   AND ap.cycle_id = sa.cycle_id
                                   AND ap.supervisor_id = sa.supervisor_id
            WHERE sa.supervisor_id = ? AND sa.cycle_id = ?
        ");
        $statsStmt->bind_param("ii", $id, $cycle['id']);
        $statsStmt->execute();
        $stats = $statsStmt->get_result()->fetch_assoc();
        $statsStmt->close();

        $subordinateStats['total']    = (int) $stats['total'];
        $subordinateStats['appraised']= (int) $stats['appraised'];
        $subordinateStats['pending']  = max(0, $subordinateStats['total'] - $subordinateStats['appraised']);
        $subordinateStats['progress'] = $subordinateStats['total'] > 0
            ? round(($subordinateStats['appraised'] / $subordinateStats['total']) * 100, 1)
            : 0;

        // Onboarding status for cycle
        $onboardStmt = $conn->prepare("
            SELECT onboarded_at FROM supervisor_onboarding
            WHERE supervisor_id = ? AND cycle_id = ? LIMIT 1
        ");
        $onboardStmt->bind_param("ii", $id, $cycle['id']);
        $onboardStmt->execute();
        $onboarding      = $onboardStmt->get_result()->fetch_assoc();
        $onboardingStatus= $onboarding ? $onboarding['onboarded_at'] : null;
        $onboardStmt->close();
    }

    $supervisor['cycle']              = $cycle;
    $supervisor['subordinate_stats']  = $subordinateStats;
    $supervisor['is_onboarded']       = !empty($onboardingStatus);
    $supervisor['cycle_onboarded_at'] = $onboardingStatus;

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Supervisor fetched successfully",
        "data"    => $supervisor
    ]);

} catch (Exception $e) {
    error_log("GetSingleSupervisor Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
