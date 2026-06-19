<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

function jsonResponse($status, $message, $data = [], $meta = [], $code = 200) {
    http_response_code($code);
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data, 'meta' => $meta]);
    exit;
}

function esc($conn, $value) { return $conn->real_escape_string(trim((string)$value)); }
function fetchOneRaw($conn, $sql) { $r = $conn->query($sql); if (!$r) throw new Exception('Database error: '.$conn->error, 500); return $r->fetch_assoc(); }
function fetchAllRaw($conn, $sql) { $r = $conn->query($sql); if (!$r) throw new Exception('Database error: '.$conn->error, 500); $rows=[]; while($row=$r->fetch_assoc()) $rows[]=$row; return $rows; }
function roleKeySql($alias) { return "LOWER(REPLACE(TRIM({$alias}.name), ' ', '_'))"; }

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Bad Request: Only GET method is allowed', 405);

    $userData = requireRoles(['super_admin', 'admin', 'supervisor']);
    $loggedInUserId = (int)$userData['id'];
    $loggedInRole = $userData['role'] ?? '';
    $loggedInRoleKey = strtolower(str_replace(' ', '_', trim((string)$loggedInRole)));
    $loggedInCompanyId = isset($userData['company_id']) ? (int)$userData['company_id'] : 0;

    $cycleId = isset($_GET['cycle_id']) ? (int)$_GET['cycle_id'] : 0;
    $supervisorId = isset($_GET['supervisor_id']) ? (int)$_GET['supervisor_id'] : 0;
    if ($loggedInRoleKey === 'supervisor') $supervisorId = $loggedInUserId;

    $search = esc($conn, $_GET['search'] ?? '');
    $department = esc($conn, $_GET['department'] ?? '');
    $staffType = esc($conn, $_GET['staff_type'] ?? '');
    $status = esc($conn, $_GET['status'] ?? 'all');

    $page = isset($_GET['page']) ? max((int)$_GET['page'], 1) : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $limit = in_array($limit, [10,20,50,100], true) ? $limit : 10;
    $offset = ($page - 1) * $limit;

    $emptyMeta = [
        'total' => 0, 'page' => $page, 'limit' => $limit, 'total_pages' => 1,
        'assigned_count' => 0, 'appraised_count' => 0, 'pending_count' => 0,
        'progress_percent' => 0, 'cycle' => null, 'supervisor' => null,
    ];

    if ($cycleId <= 0) {
        jsonResponse('Success', 'Select a cycle to load appraisal candidates.', [], $emptyMeta);
    }
    $allSupervisors = $supervisorId <= 0 && in_array($loggedInRoleKey, ['super_admin', 'admin'], true);

    $companyScope = resolveCompanyScope($userData);
    $cycleCompanySql = $companyScope !== null ? " AND company_id = " . (int) $companyScope : '';
    $cycle = fetchOneRaw($conn, "
        SELECT id, company_id, year, title, is_active
        FROM appraisal_cycles
        WHERE id = {$cycleId} {$cycleCompanySql}
        LIMIT 1
    ");
    if (!$cycle) throw new Exception('Selected appraisal cycle was not found or is outside your company scope.', 404);
    $companyId = (int)$cycle['company_id'];

    $supervisor = null;
    if (!$allSupervisors) {
        $supervisor = fetchOneRaw($conn, "
            SELECT u.id, u.first_name, u.last_name, u.email, u.department, u.job_title, u.staff_type, r.name AS role_name
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            WHERE u.id = {$supervisorId}
              AND " . appraiserRoleWhere('r') . "
              AND u.company_id = {$companyId}
              AND u.is_active = 1
            LIMIT 1
        " );
        if (!$supervisor) throw new Exception('Selected supervisor was not found for this appraisal cycle.', 404);
    }

    $where = ["sa.cycle_id = {$cycleId}", "s.company_id = {$companyId}", "s.is_active = 1", appraiseeRoleWhere('sr')];
    if (!$allSupervisors) $where[] = "sa.supervisor_id = {$supervisorId}";
    if ($search !== '') {
        $where[] = "(s.first_name LIKE '%{$search}%' OR s.last_name LIKE '%{$search}%' OR s.fullname LIKE '%{$search}%' OR s.email LIKE '%{$search}%' OR s.staff_id LIKE '%{$search}%' OR s.unique_ref LIKE '%{$search}%' OR s.department LIKE '%{$search}%' OR s.job_title LIKE '%{$search}%')";
    }
    if ($department !== '') $where[] = "s.department = '{$department}'";
    if ($staffType !== '') $where[] = "s.staff_type = '{$staffType}'";
    $adminScope = $loggedInRoleKey === 'admin' ? trim((string) ($userData['staff_scope'] ?? 'All')) : 'All';
    if (in_array($adminScope, ['Local', 'Expatriate'], true)) {
        $adminScopeSafe = esc($conn, $adminScope);
        $where[] = "(" . roleKeySql('sr') . " <> 'staff' OR s.staff_type = '{$adminScopeSafe}')";
    }
    if ($status === 'pending') $where[] = "ap.id IS NULL";
    if ($status === 'appraised') $where[] = "ap.id IS NOT NULL";
    if (in_array($status, ['Pending','Acknowledged'], true)) $where[] = "ap.status = '{$status}'";

    $whereSql = implode(' AND ', $where);

    $baseFrom = "
        FROM supervisor_assignments sa
        INNER JOIN users s ON s.id = sa.staff_id
        INNER JOIN roles sr ON sr.id = s.role_id
        LEFT JOIN users sup ON sup.id = sa.supervisor_id
        LEFT JOIN appraisals ap ON ap.staff_user_id = s.id AND ap.cycle_id = sa.cycle_id
        WHERE {$whereSql}
    ";

    $count = fetchOneRaw($conn, "SELECT COUNT(*) AS total {$baseFrom}");
    $total = (int)($count['total'] ?? 0);
    $totalPages = max((int)ceil($total / $limit), 1);
    if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $limit; }

    $rows = fetchAllRaw($conn, "
        SELECT
            s.id AS staff_user_id,
            s.first_name, s.last_name, s.fullname, s.email, s.staff_id, s.unique_ref,
            s.department, s.job_title, s.staff_type, s.location, s.date_of_joining,
            sa.supervisor_id,
            ap.id AS appraisal_id,
            ap.status AS appraisal_status,
            ap.kpi_rating,
            ap.appraisal_summary,
            ap.evaluation_statement,
            ap.feedback,
            ap.edited_count,
            ap.update_count,
            ap.created_at AS appraised_at,
            ap.updated_at AS appraisal_updated_at,
            TRIM(CONCAT(COALESCE(sup.first_name, ''), ' ', COALESCE(sup.last_name, ''))) AS supervisor_name,
            CASE
                WHEN sa.supervisor_id = {$loggedInUserId}
                 AND '{$loggedInRoleKey}' IN ('admin', 'supervisor')
                 AND EXISTS (
                    SELECT 1
                    FROM supervisor_onboarding so
                    WHERE so.cycle_id = sa.cycle_id
                      AND so.supervisor_id = sa.supervisor_id
                    LIMIT 1
                 )
                THEN 1
                ELSE 0
            END AS can_manage_appraisal
        {$baseFrom}
        ORDER BY s.first_name ASC, s.last_name ASC, s.id ASC
        LIMIT {$limit} OFFSET {$offset}
    ");

    $stats = fetchOneRaw($conn, "
        SELECT
            COUNT(sa.id) AS assigned_count,
            COALESCE(SUM(CASE WHEN ap.id IS NOT NULL THEN 1 ELSE 0 END), 0) AS appraised_count
        FROM supervisor_assignments sa
        INNER JOIN users s ON s.id = sa.staff_id
        INNER JOIN roles sr ON sr.id = s.role_id
        LEFT JOIN users sup ON sup.id = sa.supervisor_id
        LEFT JOIN appraisals ap ON ap.staff_user_id = s.id AND ap.cycle_id = sa.cycle_id
        WHERE sa.cycle_id = {$cycleId}
          AND ( {$supervisorId} <= 0 OR sa.supervisor_id = {$supervisorId} )
          AND s.company_id = {$companyId}
          AND s.is_active = 1
          AND " . appraiseeRoleWhere('sr') . "
          " . (in_array($adminScope, ['Local', 'Expatriate'], true) ? " AND (" . roleKeySql('sr') . " <> 'staff' OR s.staff_type = '" . esc($conn, $adminScope) . "')" : '') . "
    ");

    $assignedCount = (int)($stats['assigned_count'] ?? 0);
    $appraisedCount = (int)($stats['appraised_count'] ?? 0);
    $pendingCount = max($assignedCount - $appraisedCount, 0);
    $progressPercent = $assignedCount > 0 ? round(($appraisedCount / $assignedCount) * 100, 1) : 0;

    $onboard = $allSupervisors ? null : fetchOneRaw($conn, "SELECT id FROM supervisor_onboarding WHERE cycle_id = {$cycleId} AND supervisor_id = {$supervisorId} LIMIT 1");

    $supervisorMeta = $allSupervisors ? ['id' => 0, 'full_name' => 'All Supervisors', 'is_onboarded' => 1] : $supervisor;
    if (!$allSupervisors) {
        $supervisorMeta['full_name'] = trim(($supervisor['first_name'] ?? '') . ' ' . ($supervisor['last_name'] ?? ''));
        $supervisorMeta['is_onboarded'] = $onboard ? 1 : 0;
    }

    jsonResponse('Success', 'Appraisal candidates fetched successfully.', $rows, [
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => $totalPages,
        'assigned_count' => $assignedCount,
        'appraised_count' => $appraisedCount,
        'pending_count' => $pendingCount,
        'progress_percent' => $progressPercent,
        'cycle' => [
            'id' => (int)$cycle['id'],
            'title' => $cycle['title'],
            'year' => $cycle['year'],
            'is_active' => (int)$cycle['is_active'],
        ],
        'supervisor' => $supervisorMeta,
    ]);

} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    jsonResponse('Failed', $e->getMessage(), [], [], $code);
}
