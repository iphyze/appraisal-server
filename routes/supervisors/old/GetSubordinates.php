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

    /**
     * supervisor_id resolution:
     *   - Supervisor calling → always their own ID
     *   - Admin/super_admin → must pass ?supervisor_id= in query
     */
    if ($loggedInRole === 'supervisor') {
        $supervisorId = $loggedInUserId;
    } elseif (in_array($loggedInRole, ['super_admin', 'admin'])) {
        if (!isset($_GET['supervisor_id']) || !is_numeric($_GET['supervisor_id'])) {
            throw new Exception("Missing required parameter: 'supervisor_id'.", 400);
        }
        $supervisorId = (int) $_GET['supervisor_id'];
    } else {
        throw new Exception("Unauthorized.", 403);
    }

    // Validate supervisor
    $supStmt = $conn->prepare("
        SELECT u.id, u.company_id, u.first_name, u.last_name, r.name AS role
        FROM users u INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = ? AND r.name = 'supervisor' LIMIT 1
    ");
    $supStmt->bind_param("i", $supervisorId);
    $supStmt->execute();
    $supervisor = $supStmt->get_result()->fetch_assoc();
    $supStmt->close();

    if (!$supervisor) throw new Exception("Supervisor not found.", 404);

    if (
        $loggedInRole !== 'super_admin' &&
        (int) $supervisor['company_id'] !== $loggedInCompanyId
    ) {
        throw new Exception("Unauthorized: Supervisor does not belong to your company.", 403);
    }

    // Optional filters
    $cycleId  = isset($_GET['cycle_id'])  ? (int) $_GET['cycle_id']  : null;
    $pending  = isset($_GET['pending'])   ? (int) $_GET['pending']   : null; // 1 = not yet appraised
    $search   = isset($_GET['search'])    ? trim($_GET['search'])    : '';

    // Pagination
    $limit  = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
    $page   = isset($_GET['page'])  ? (int) $_GET['page']  : 1;
    if ($limit <= 0) $limit = 50;
    if ($page  <= 0) $page  = 1;
    $offset = ($page - 1) * $limit;

    // Resolve cycle — use provided or fall back to active cycle
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

    if (!$cycle) throw new Exception("No active appraisal cycle found.", 404);

    // Build query
    $baseQuery = "
        FROM supervisor_assignments sa
        INNER JOIN users u     ON u.id  = sa.staff_id
        INNER JOIN roles r     ON r.id  = u.role_id
        INNER JOIN companies c ON c.id  = u.company_id
        LEFT  JOIN appraisals ap ON ap.staff_user_id = sa.staff_id
                               AND ap.cycle_id       = sa.cycle_id
                               AND ap.supervisor_id  = sa.supervisor_id
        WHERE sa.supervisor_id = ? AND sa.cycle_id = ?
    ";
    $params = [$supervisorId, $cycle['id']];
    $types  = "ii";

    // Pending filter — show only staff not yet appraised
    if ($pending === 1) {
        $baseQuery .= " AND ap.id IS NULL";
    } elseif ($pending === 0) {
        $baseQuery .= " AND ap.id IS NOT NULL";
    }

    if (!empty($search)) {
        $baseQuery .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.department LIKE ?)";
        $like       = "%" . $search . "%";
        $params     = array_merge($params, [$like, $like, $like, $like]);
        $types     .= "ssss";
    }

    // Count
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total " . $baseQuery);
    if (!$countStmt) throw new Exception("Database error: " . $conn->error, 500);
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // Fetch
    $dataQuery = "
        SELECT
            u.id,
            u.staff_id,
            u.first_name,
            u.last_name,
            u.email,
            u.department,
            u.job_title,
            u.staff_type,
            u.location,
            u.date_of_joining,
            u.unique_ref,
            u.is_active,
            c.id    AS company_id,
            c.code  AS company_code,
            -- Appraisal status for this cycle
            ap.id               AS appraisal_id,
            ap.status           AS appraisal_status,
            ap.appraisal_summary,
            ap.evaluation_statement,
            ap.kpi_rating,
            ap.created_at       AS appraised_at,
            CASE WHEN ap.id IS NOT NULL THEN 1 ELSE 0 END AS is_appraised
        " . $baseQuery . "
        ORDER BY is_appraised ASC, u.first_name ASC, u.last_name ASC
        LIMIT ? OFFSET ?
    ";

    $dataStmt = $conn->prepare($dataQuery);
    if (!$dataStmt) throw new Exception("Database error: " . $conn->error, 500);

    $types   .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    $dataStmt->bind_param($types, ...$params);
    $dataStmt->execute();
    $subordinates = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    // Summary counts
    $appraised = count(array_filter($subordinates, fn($s) => (int)$s['is_appraised'] === 1));
    $pending   = count(array_filter($subordinates, fn($s) => (int)$s['is_appraised'] === 0));

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Subordinates fetched successfully",
        "data"    => $subordinates,
        "meta"    => [
            "total"            => $total,
            "page"             => $page,
            "limit"            => $limit,
            "total_pages"      => (int) ceil($total / $limit),
            "appraised_count"  => $appraised,
            "pending_count"    => $pending,
            "progress_percent" => $total > 0 ? round(($appraised / $total) * 100, 1) : 0,
            "supervisor"       => [
                "id"   => $supervisorId,
                "name" => $supervisor['first_name'] . " " . $supervisor['last_name'],
            ],
            "cycle"            => $cycle,
            "filters"          => [
                "pending" => isset($_GET['pending']) ? (int)$_GET['pending'] : null,
                "search"  => $search ?: null,
            ]
        ]
    ]);

} catch (Exception $e) {
    error_log("GetSubordinates Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
