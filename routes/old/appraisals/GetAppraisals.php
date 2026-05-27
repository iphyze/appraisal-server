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
    $companyScope      = resolveCompanyScope($userData);
    $clause            = buildCompanyWhereClause($companyScope, 'ap');

    // Filters
    $cycleId      = isset($_GET['cycle_id'])     ? (int) $_GET['cycle_id']     : null;
    $supervisorId = isset($_GET['supervisor_id'])? (int) $_GET['supervisor_id']: null;
    $staffUserId  = isset($_GET['staff_user_id'])? (int) $_GET['staff_user_id']: null;
    $department   = isset($_GET['department'])   ? trim($_GET['department'])    : null;
    $staffType    = isset($_GET['staff_type'])   ? trim($_GET['staff_type'])    : null;
    $status       = isset($_GET['status'])       ? trim($_GET['status'])        : null;
    $search       = isset($_GET['search'])       ? trim($_GET['search'])        : '';

    // Admin staff_scope restriction
    $staffScopeFilter = null;
    if ($loggedInRole === 'admin') {
        $adminScope = $userData['staff_scope'] ?? 'All';
        if ($adminScope !== 'All') $staffScopeFilter = $adminScope;
    }

    // Pagination
    $limit  = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
    $page   = isset($_GET['page'])  ? (int) $_GET['page']  : 1;
    if ($limit <= 0) $limit = 20;
    if ($page  <= 0) $page  = 1;
    $offset = ($page - 1) * $limit;

    // Sorting
    $allowedSort = ['id', 'staff_fullname', 'appraisal_summary', 'kpi_rating', 'created_at', 'status'];
    $sortBy    = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSort)
                    ? $_GET['sortBy'] : 'created_at';
    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === 'ASC'
                    ? 'ASC' : 'DESC';

    // Base query
    $baseQuery = "
        FROM appraisals ap
        INNER JOIN appraisal_cycles ac  ON ac.id = ap.cycle_id
        INNER JOIN companies c          ON c.id  = ap.company_id
        LEFT  JOIN users sup            ON sup.id = ap.supervisor_id
        WHERE 1=1
    ";
    $params = [];
    $types  = "";

    // ── Role-based scoping ────────────────────────────────────────────────────
    if ($loggedInRole === 'supervisor') {
        // Supervisors only see their own appraisals
        $baseQuery .= " AND ap.supervisor_id = ?";
        $params[]   = $loggedInUserId;
        $types     .= "i";
    } elseif ($loggedInRole === 'staff') {
        // Staff only see their own appraisal record
        $baseQuery .= " AND ap.staff_user_id = ?";
        $params[]   = $loggedInUserId;
        $types     .= "i";
    } else {
        // Admin/super_admin — apply company scope
        if ($clause['value'] !== null) {
            $baseQuery .= " AND ap.company_id = ?";
            $params[]   = $clause['value'];
            $types     .= "i";
        }
    }

    if ($cycleId) {
        $baseQuery .= " AND ap.cycle_id = ?";
        $params[]   = $cycleId;
        $types     .= "i";
    }

    if ($supervisorId && in_array($loggedInRole, ['super_admin', 'admin'])) {
        $baseQuery .= " AND ap.supervisor_id = ?";
        $params[]   = $supervisorId;
        $types     .= "i";
    }

    if ($staffUserId && in_array($loggedInRole, ['super_admin', 'admin', 'supervisor'])) {
        $baseQuery .= " AND ap.staff_user_id = ?";
        $params[]   = $staffUserId;
        $types     .= "i";
    }

    if ($department) {
        $baseQuery .= " AND ap.staff_department = ?";
        $params[]   = $department;
        $types     .= "s";
    }

    if ($staffType) {
        $baseQuery .= " AND ap.staff_type = ?";
        $params[]   = $staffType;
        $types     .= "s";
    }

    if ($staffScopeFilter) {
        $baseQuery .= " AND ap.staff_type = ?";
        $params[]   = $staffScopeFilter;
        $types     .= "s";
    }

    if ($status && in_array($status, ['Pending', 'Confirmed', 'Rejected'])) {
        $baseQuery .= " AND ap.status = ?";
        $params[]   = $status;
        $types     .= "s";
    }

    if (!empty($search)) {
        $baseQuery .= " AND (ap.staff_fullname LIKE ? OR ap.staff_department LIKE ? OR ap.staff_email LIKE ?)";
        $like       = "%" . $search . "%";
        $params     = array_merge($params, [$like, $like, $like]);
        $types     .= "sss";
    }

    // Count
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total " . $baseQuery);
    if (!$countStmt) throw new Exception("Database error: " . $conn->error, 500);
    if (!empty($params)) $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // Fetch
    $dataQuery = "
        SELECT
            ap.id,
            ap.staff_user_id,
            ap.supervisor_id,
            ap.staff_fullname,
            ap.staff_department,
            ap.staff_job_title,
            ap.staff_type,
            ap.staff_email,
            ap.staff_location,
            ap.date_of_joining,
            ap.duration_years,
            ap.kpi_rating,
            ap.appraisal_summary,
            ap.evaluation_statement,
            ap.status,
            ap.feedback,
            ap.edited_count,
            ap.created_at,
            ap.updated_at,
            ac.id    AS cycle_id,
            ac.year  AS cycle_year,
            ac.title AS cycle_title,
            c.id     AS company_id,
            c.code   AS company_code,
            c.name   AS company_name,
            CONCAT(sup.first_name,' ',sup.last_name) AS supervisor_name,
            sup.email AS supervisor_email
        " . $baseQuery . "
        ORDER BY ap.{$sortBy} {$sortOrder}
        LIMIT ? OFFSET ?
    ";

    $dataStmt = $conn->prepare($dataQuery);
    if (!$dataStmt) throw new Exception("Database error: " . $conn->error, 500);

    $types   .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    $dataStmt->bind_param($types, ...$params);
    $dataStmt->execute();
    $data = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Appraisals fetched successfully",
        "data"    => $data,
        "meta"    => [
            "total"       => $total,
            "page"        => $page,
            "limit"       => $limit,
            "total_pages" => (int) ceil($total / $limit),
            "sortBy"      => $sortBy,
            "sortOrder"   => $sortOrder,
            "filters"     => [
                "cycle_id"      => $cycleId,
                "supervisor_id" => $supervisorId,
                "staff_user_id" => $staffUserId,
                "department"    => $department,
                "staff_type"    => $staffType,
                "status"        => $status,
                "search"        => $search ?: null,
                "company_scope" => $companyScope,
            ]
        ]
    ]);

} catch (Exception $e) {
    error_log("GetAppraisals Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
