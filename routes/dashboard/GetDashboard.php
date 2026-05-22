<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 400);
    }

    $userData          = authenticateUser();
    $loggedInUserId    = (int) $userData['id'];
    $loggedInRole      = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    // Super admin can switch company view
    $companyScope = resolveCompanyScope($userData);

    // Optional cycle filter — defaults to active cycle
    $cycleId = isset($_GET['cycle_id']) ? (int) $_GET['cycle_id'] : null;

    // ── Helper: resolve active cycle for a company ────────────────────────────
    function getActiveCycle($conn, int $companyId, ?int $cycleId): ?array
    {
        if ($cycleId) {
            $stmt = $conn->prepare("
                SELECT id, year, title, start_date, end_date, is_active
                FROM appraisal_cycles WHERE id = ? AND company_id = ? LIMIT 1
            ");
            $stmt->bind_param("ii", $cycleId, $companyId);
        } else {
            $stmt = $conn->prepare("
                SELECT id, year, title, start_date, end_date, is_active
                FROM appraisal_cycles
                WHERE company_id = ? AND is_active = 1 LIMIT 1
            ");
            $stmt->bind_param("i", $companyId);
        }
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result;
    }

    // ── SUPER ADMIN DASHBOARD ─────────────────────────────────────────────────
    if ($loggedInRole === 'super_admin') {

        // Fetch all companies
        $companiesStmt = $conn->prepare("SELECT id, code, name FROM companies WHERE is_active = 1");
        $companiesStmt->execute();
        $companies = $companiesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $companiesStmt->close();

        $companyStats = [];

        foreach ($companies as $company) {
            // Skip if super admin is filtering by a specific company
            if ($companyScope !== null && (int)$company['id'] !== $companyScope) continue;

            $cId   = (int) $company['id'];
            $cycle = getActiveCycle($conn, $cId, $cycleId);

            if (!$cycle) {
                $companyStats[] = array_merge($company, ['cycle' => null, 'stats' => null]);
                continue;
            }

            // Total staff
            $totalStmt = $conn->prepare("
                SELECT COUNT(*) AS cnt FROM users u
                INNER JOIN roles r ON r.id = u.role_id
                WHERE u.company_id = ? AND LOWER(REPLACE(TRIM(r.name), ' ', '_')) <> 'super_admin' AND u.is_active = 1
            ");
            $totalStmt->bind_param("i", $cId);
            $totalStmt->execute();
            $totalStaff = (int)$totalStmt->get_result()->fetch_assoc()['cnt'];
            $totalStmt->close();

            // Total supervisors
            $supCountStmt = $conn->prepare("
                SELECT COUNT(*) AS cnt FROM users u
                INNER JOIN roles r ON r.id = u.role_id
                WHERE u.company_id = ? AND LOWER(REPLACE(TRIM(r.name), ' ', '_')) IN ('admin', 'supervisor') AND u.is_active = 1
            ");
            $supCountStmt->bind_param("i", $cId);
            $supCountStmt->execute();
            $totalSupervisors = (int)$supCountStmt->get_result()->fetch_assoc()['cnt'];
            $supCountStmt->close();

            // Appraisal progress
            $aprStmt = $conn->prepare("
                SELECT
                    COUNT(*)                                            AS total_appraised,
                    SUM(CASE WHEN status = 'Acknowledged' THEN 1 ELSE 0 END) AS acknowledged,
                    SUM(CASE WHEN status = 'Pending'      THEN 1 ELSE 0 END) AS pending,
                    ROUND(AVG(appraisal_summary), 2)                   AS avg_score
                FROM appraisals
                WHERE company_id = ? AND cycle_id = ?
            ");
            $aprStmt->bind_param("ii", $cId, $cycle['id']);
            $aprStmt->execute();
            $aprStats = $aprStmt->get_result()->fetch_assoc();
            $aprStmt->close();

            // Score distribution
            $distStmt = $conn->prepare("
                SELECT evaluation_statement, COUNT(*) AS cnt
                FROM appraisals
                WHERE company_id = ? AND cycle_id = ?
                GROUP BY evaluation_statement
                ORDER BY cnt DESC
            ");
            $distStmt->bind_param("ii", $cId, $cycle['id']);
            $distStmt->execute();
            $scoreDistribution = $distStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $distStmt->close();

            // Onboarded supervisors
            $onboardStmt = $conn->prepare("
                SELECT COUNT(DISTINCT supervisor_id) AS cnt
                FROM supervisor_onboarding
                WHERE cycle_id = ?
            ");
            $onboardStmt->bind_param("i", $cycle['id']);
            $onboardStmt->execute();
            $onboardedCount = (int)$onboardStmt->get_result()->fetch_assoc()['cnt'];
            $onboardStmt->close();

            $totalAppraised = (int)$aprStats['total_appraised'];
            $progress       = $totalStaff > 0
                ? round(($totalAppraised / $totalStaff) * 100, 1)
                : 0;

            $companyStats[] = [
                'id'           => $company['id'],
                'code'         => $company['code'],
                'name'         => $company['name'],
                'cycle'        => $cycle,
                'stats'        => [
                    'total_staff'         => $totalStaff,
                    'total_supervisors'   => $totalSupervisors,
                    'onboarded_supervisors'=> $onboardedCount,
                    'total_appraised'     => $totalAppraised,
                    'pending_appraisals'  => $totalStaff - $totalAppraised,
                    'progress_percent'    => $progress,
                    'acknowledged'        => (int)$aprStats['acknowledged'],
                    'pending_status'      => (int)$aprStats['pending'],
                    'avg_score'           => $aprStats['avg_score'] ?? null,
                    'score_distribution'  => $scoreDistribution,
                ],
            ];
        }

        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => "Super admin dashboard fetched successfully",
            "data"    => [
                "role"          => "super_admin",
                "view_company"  => $companyScope,
                "companies"     => $companyStats,
            ]
        ]);
        exit;
    }

    // ── ADMIN DASHBOARD ───────────────────────────────────────────────────────
    if ($loggedInRole === 'admin') {
        $adminScope = $userData['staff_scope'] ?? 'All'; // All | Local | Expatriate
        $cycle      = getActiveCycle($conn, $loggedInCompanyId, $cycleId);

        // An administrator may also be an assigned appraiser. This status powers
        // the onboarding prompt without taking away their administration workspace.
        $appraiserAssignmentCount = 0;
        $appraiserIsOnboarded = false;
        $appraiserOnboardedAt = null;
        if ($cycle) {
            $adminAssignmentStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM supervisor_assignments WHERE supervisor_id = ? AND cycle_id = ?");
            $adminAssignmentStmt->bind_param("ii", $loggedInUserId, $cycle['id']);
            $adminAssignmentStmt->execute();
            $appraiserAssignmentCount = (int) ($adminAssignmentStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $adminAssignmentStmt->close();

            $adminOnboardStmt = $conn->prepare("SELECT onboarded_at FROM supervisor_onboarding WHERE supervisor_id = ? AND cycle_id = ? LIMIT 1");
            $adminOnboardStmt->bind_param("ii", $loggedInUserId, $cycle['id']);
            $adminOnboardStmt->execute();
            $adminOnboarding = $adminOnboardStmt->get_result()->fetch_assoc();
            $adminOnboardStmt->close();
            $appraiserIsOnboarded = !empty($adminOnboarding);
            $appraiserOnboardedAt = $adminOnboarding['onboarded_at'] ?? null;
        }

        // Build staff_type filter
        $typeFilter = '';
        $typeParams = [];
        $typeTypes  = '';
        if ($adminScope !== 'All') {
            // Admin scope restricts normal staff only. Administrators or supervisors who
            // are appraised must remain visible to administrative reporting.
            $typeFilter  = " AND (NOT EXISTS (
                SELECT 1
                FROM users target_user
                INNER JOIN roles target_role ON target_role.id = target_user.role_id
                WHERE target_user.id = ap.staff_user_id
                  AND LOWER(REPLACE(TRIM(target_role.name), ' ', '_')) = 'staff'
            ) OR ap.staff_type = ?)";
            $typeParams[] = $adminScope;
            $typeTypes   .= "s";
        }

        // Overall appraisal stats
        $params = [$loggedInCompanyId];
        $types  = "i";

        $cycleFilter = "";
        if ($cycle) {
            $cycleFilter  = " AND ap.cycle_id = ?";
            $params[]     = $cycle['id'];
            $types       .= "i";
        }

        $aprStmt = $conn->prepare("
            SELECT
                COUNT(*)                                               AS total_appraised,
                SUM(CASE WHEN ap.status = 'Acknowledged' THEN 1 ELSE 0 END) AS acknowledged,
                SUM(CASE WHEN ap.status = 'Pending'      THEN 1 ELSE 0 END) AS pending_status,
                ROUND(AVG(ap.appraisal_summary), 2)                    AS avg_score,
                ROUND(AVG(ap.kpi_rating), 2)                           AS avg_kpi
            FROM appraisals ap
            WHERE ap.company_id = ? {$cycleFilter} {$typeFilter}
        ");
        $aprStmt->bind_param($types . $typeTypes, ...array_merge($params, $typeParams));
        $aprStmt->execute();
        $aprStats = $aprStmt->get_result()->fetch_assoc();
        $aprStmt->close();

        // Total staff (scoped)
        $staffCondition = $adminScope !== 'All' ? " AND (LOWER(REPLACE(TRIM(r.name), ' ', '_')) <> 'staff' OR u.staff_type = '{$adminScope}')" : '';
        $totalStmtQ     = $conn->prepare("
            SELECT COUNT(*) AS cnt FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            WHERE u.company_id = ? AND LOWER(REPLACE(TRIM(r.name), ' ', '_')) <> 'super_admin' AND u.is_active = 1 {$staffCondition}
        ");
        $totalStmtQ->bind_param("i", $loggedInCompanyId);
        $totalStmtQ->execute();
        $totalStaff = (int)$totalStmtQ->get_result()->fetch_assoc()['cnt'];
        $totalStmtQ->close();

        // Department breakdown
        $deptStmt = $conn->prepare("
            SELECT
                ap.staff_department AS department,
                COUNT(*)            AS appraised,
                ROUND(AVG(ap.appraisal_summary), 2) AS avg_score
            FROM appraisals ap
            WHERE ap.company_id = ? {$cycleFilter} {$typeFilter}
            GROUP BY ap.staff_department
            ORDER BY avg_score DESC
        ");
        $deptStmt->bind_param($types . $typeTypes, ...array_merge($params, $typeParams));
        $deptStmt->execute();
        $deptBreakdown = $deptStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $deptStmt->close();

        // Score distribution
        $distStmt = $conn->prepare("
            SELECT evaluation_statement, COUNT(*) AS cnt
            FROM appraisals ap
            WHERE ap.company_id = ? {$cycleFilter} {$typeFilter}
            GROUP BY evaluation_statement
            ORDER BY cnt DESC
        ");
        $distStmt->bind_param($types . $typeTypes, ...array_merge($params, $typeParams));
        $distStmt->execute();
        $scoreDistribution = $distStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $distStmt->close();

        // Supervisor progress
        $supProgressStmt = $conn->prepare("
            SELECT
                u.id,
                CONCAT(u.first_name,' ',u.last_name) AS supervisor_name,
                u.department,
                COUNT(DISTINCT sa.staff_id)           AS total_assigned,
                COUNT(DISTINCT ap.staff_user_id)      AS appraised_count
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            LEFT JOIN supervisor_assignments sa ON sa.supervisor_id = u.id
                AND sa.cycle_id = ?
            LEFT JOIN appraisals ap ON ap.supervisor_id = u.id
                AND ap.cycle_id = ?
            WHERE u.company_id = ? AND LOWER(REPLACE(TRIM(r.name), ' ', '_')) IN ('admin', 'supervisor')
            GROUP BY u.id
            ORDER BY appraised_count DESC
        ");
        $supProgressStmt->bind_param("iii", $cycle['id'], $cycle['id'], $loggedInCompanyId);
        $supProgressStmt->execute();
        $supervisorProgress = $supProgressStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $supProgressStmt->close();

        // Add progress % to each supervisor
        foreach ($supervisorProgress as &$sup) {
            $sup['pending_count']    = max(0, (int)$sup['total_assigned'] - (int)$sup['appraised_count']);
            $sup['progress_percent'] = $sup['total_assigned'] > 0
                ? round(($sup['appraised_count'] / $sup['total_assigned']) * 100, 1)
                : 0;
        }
        unset($sup);

        // Administrators may also be appraisal subjects. Their own record is exposed
        // separately without limiting the administrative reporting scope above.
        $myAppraisal = null;
        $myAppraisalHistory = [];
        if ($cycle) {
            $myStmt = $conn->prepare("
                SELECT ap.id, ap.appraisal_summary, ap.evaluation_statement, ap.status,
                       ap.kpi_rating, ap.feedback, ap.created_at, ap.updated_at,
                       CONCAT(sup.first_name,' ',sup.last_name) AS supervisor_name
                FROM appraisals ap
                LEFT JOIN users sup ON sup.id = ap.supervisor_id
                WHERE ap.staff_user_id = ? AND ap.cycle_id = ? LIMIT 1
            ");
            $myStmt->bind_param("ii", $loggedInUserId, $cycle['id']);
            $myStmt->execute();
            $myAppraisal = $myStmt->get_result()->fetch_assoc();
            $myStmt->close();
        }
        $historyStmt = $conn->prepare("
            SELECT ap.id, ap.appraisal_summary, ap.evaluation_statement, ap.status, ap.feedback,
                   ac.year AS cycle_year, ac.title AS cycle_title,
                   CONCAT(sup.first_name,' ',sup.last_name) AS supervisor_name
            FROM appraisals ap
            INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id
            LEFT JOIN users sup ON sup.id = ap.supervisor_id
            WHERE ap.staff_user_id = ?
            ORDER BY ac.year DESC, ap.id DESC
            LIMIT 10
        ");
        $historyStmt->bind_param("i", $loggedInUserId);
        $historyStmt->execute();
        $myAppraisalHistory = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $historyStmt->close();

        $totalAppraised = (int)$aprStats['total_appraised'];

        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => "Admin dashboard fetched successfully",
            "data"    => [
                "role"        => "admin",
                "staff_scope" => $adminScope,
                "cycle"       => $cycle,
                "appraiser_assignment_count" => $appraiserAssignmentCount,
                "appraiser_is_onboarded" => $appraiserIsOnboarded,
                "appraiser_onboarded_at" => $appraiserOnboardedAt,
                "my_appraisal" => $myAppraisal,
                "appraisal_history" => $myAppraisalHistory,
                "stats"       => [
                    "total_staff"        => $totalStaff,
                    "total_appraised"    => $totalAppraised,
                    "pending_appraisals" => $totalStaff - $totalAppraised,
                    "progress_percent"   => $totalStaff > 0
                        ? round(($totalAppraised / $totalStaff) * 100, 1) : 0,
                    "acknowledged"       => (int)$aprStats['acknowledged'],
                    "pending_status"     => (int)$aprStats['pending_status'],
                    "avg_score"          => $aprStats['avg_score'],
                    "avg_kpi_score"      => $aprStats['avg_kpi'],
                    "score_distribution" => $scoreDistribution,
                    "department_breakdown"    => $deptBreakdown,
                    "supervisor_progress"     => $supervisorProgress,
                ],
            ]
        ]);
        exit;
    }

    // ── SUPERVISOR DASHBOARD ──────────────────────────────────────────────────
    if ($loggedInRole === 'supervisor') {
        $cycle = getActiveCycle($conn, $loggedInCompanyId, $cycleId);

        if (!$cycle) {
            throw new Exception("No active appraisal cycle found.", 404);
        }

        // Subordinate stats
        $subStmt = $conn->prepare("
            SELECT
                COUNT(DISTINCT sa.staff_id)           AS total_assigned,
                COUNT(DISTINCT ap.staff_user_id)      AS appraised_count,
                ROUND(AVG(ap.appraisal_summary), 2)   AS avg_score,
                ROUND(AVG(ap.kpi_rating), 2)           AS avg_kpi
            FROM supervisor_assignments sa
            LEFT JOIN appraisals ap ON ap.staff_user_id = sa.staff_id
                AND ap.cycle_id = sa.cycle_id
                AND ap.supervisor_id = sa.supervisor_id
            WHERE sa.supervisor_id = ? AND sa.cycle_id = ?
        ");
        $subStmt->bind_param("ii", $loggedInUserId, $cycle['id']);
        $subStmt->execute();
        $subStats = $subStmt->get_result()->fetch_assoc();
        $subStmt->close();

        // Pending subordinates (not yet appraised)
        $pendingStmt = $conn->prepare("
            SELECT
                u.id, u.first_name, u.last_name, u.department,
                u.job_title, u.staff_type, u.email
            FROM supervisor_assignments sa
            INNER JOIN users u ON u.id = sa.staff_id
            LEFT JOIN appraisals ap ON ap.staff_user_id = sa.staff_id
                AND ap.cycle_id = sa.cycle_id
            WHERE sa.supervisor_id = ? AND sa.cycle_id = ?
              AND ap.id IS NULL
            ORDER BY u.first_name ASC
            LIMIT 10
        ");
        $pendingStmt->bind_param("ii", $loggedInUserId, $cycle['id']);
        $pendingStmt->execute();
        $pendingSubordinates = $pendingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $pendingStmt->close();

        // Score distribution of completed appraisals
        $distStmt = $conn->prepare("
            SELECT evaluation_statement, COUNT(*) AS cnt
            FROM appraisals
            WHERE supervisor_id = ? AND cycle_id = ?
            GROUP BY evaluation_statement
            ORDER BY cnt DESC
        ");
        $distStmt->bind_param("ii", $loggedInUserId, $cycle['id']);
        $distStmt->execute();
        $scoreDistribution = $distStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $distStmt->close();

        // Onboarding status
        $onboardStmt = $conn->prepare("
            SELECT onboarded_at FROM supervisor_onboarding
            WHERE supervisor_id = ? AND cycle_id = ? LIMIT 1
        ");
        $onboardStmt->bind_param("ii", $loggedInUserId, $cycle['id']);
        $onboardStmt->execute();
        $onboarding = $onboardStmt->get_result()->fetch_assoc();
        $onboardStmt->close();

        // Check if supervisor themselves has been appraised this cycle
        $selfAppraisalStmt = $conn->prepare("
            SELECT ap.id, ap.appraisal_summary, ap.evaluation_statement, ap.status, ap.kpi_rating,
                   ap.feedback, ap.created_at, ap.updated_at,
                   CONCAT(sup.first_name,' ',sup.last_name) AS supervisor_name
            FROM appraisals ap
            LEFT JOIN users sup ON sup.id = ap.supervisor_id
            WHERE ap.staff_user_id = ? AND ap.cycle_id = ? LIMIT 1
        ");
        $selfAppraisalStmt->bind_param("ii", $loggedInUserId, $cycle['id']);
        $selfAppraisalStmt->execute();
        $selfAppraisal = $selfAppraisalStmt->get_result()->fetch_assoc();
        $selfAppraisalStmt->close();

        $totalAssigned  = (int)$subStats['total_assigned'];
        $appraised      = (int)$subStats['appraised_count'];

        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => "Supervisor dashboard fetched successfully",
            "data"    => [
                "role"         => "supervisor",
                "cycle"        => $cycle,
                "is_onboarded" => !empty($onboarding),
                "onboarded_at" => $onboarding['onboarded_at'] ?? null,
                "stats"        => [
                    "total_assigned"     => $totalAssigned,
                    "appraised_count"    => $appraised,
                    "pending_count"      => max(0, $totalAssigned - $appraised),
                    "progress_percent"   => $totalAssigned > 0
                        ? round(($appraised / $totalAssigned) * 100, 1) : 0,
                    "avg_score"          => $subStats['avg_score'],
                    "avg_kpi_score"      => $subStats['avg_kpi'],
                    "score_distribution" => $scoreDistribution,
                ],
                "pending_subordinates" => $pendingSubordinates,
                "my_appraisal"         => $selfAppraisal,
            ]
        ]);
        exit;
    }

    // ── STAFF DASHBOARD ───────────────────────────────────────────────────────
    if ($loggedInRole === 'staff') {
        $cycle = getActiveCycle($conn, $loggedInCompanyId, $cycleId);

        // Fetch all appraisals for this staff across all cycles
        $aprHistoryStmt = $conn->prepare("
            SELECT
                ap.id, ap.appraisal_summary, ap.kpi_rating,
                ap.evaluation_statement, ap.status,
                ap.feedback, ap.edited_count,
                ap.created_at, ap.updated_at,
                ac.year AS cycle_year, ac.title AS cycle_title,
                CONCAT(sup.first_name,' ',sup.last_name) AS supervisor_name
            FROM appraisals ap
            INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id
            LEFT  JOIN users sup ON sup.id = ap.supervisor_id
            WHERE ap.staff_user_id = ?
            ORDER BY ac.year DESC
        ");
        $aprHistoryStmt->bind_param("i", $loggedInUserId);
        $aprHistoryStmt->execute();
        $appraisalHistory = $aprHistoryStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $aprHistoryStmt->close();

        // Current cycle appraisal with section scores
        $currentAppraisal = null;
        $sectionScores    = [];
        if ($cycle) {
            $curAprStmt = $conn->prepare("
                SELECT ap.id, ap.appraisal_summary, ap.kpi_rating,
                       ap.evaluation_statement, ap.status, ap.feedback,
                       ap.edited_count, ap.salary_upgrade, ap.status_upgrade,
                       ap.development, ap.created_at,
                       CONCAT(sup.first_name,' ',sup.last_name) AS supervisor_name
                FROM appraisals ap
                LEFT JOIN users sup ON sup.id = ap.supervisor_id
                WHERE ap.staff_user_id = ? AND ap.cycle_id = ? LIMIT 1
            ");
            $curAprStmt->bind_param("ii", $loggedInUserId, $cycle['id']);
            $curAprStmt->execute();
            $currentAppraisal = $curAprStmt->get_result()->fetch_assoc();
            $curAprStmt->close();

            if ($currentAppraisal) {
                $scoresStmt = $conn->prepare("
                    SELECT section_code, section_label,
                           section_avg
                    FROM appraisal_section_scores
                    WHERE appraisal_id = ?
                    ORDER BY section_code ASC
                ");
                $scoresStmt->bind_param("i", $currentAppraisal['id']);
                $scoresStmt->execute();
                $sectionScores = $scoresStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $scoresStmt->close();
            }
        }

        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => "Staff dashboard fetched successfully",
            "data"    => [
                "role"              => "staff",
                "cycle"             => $cycle,
                "current_appraisal" => $currentAppraisal,
                "section_scores"    => $sectionScores,
                "appraisal_history" => $appraisalHistory,
                "total_cycles"      => count($appraisalHistory),
            ]
        ]);
        exit;
    }

    throw new Exception("Unauthorized: Unknown role.", 403);

} catch (Exception $e) {
    error_log("GetDashboard Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}