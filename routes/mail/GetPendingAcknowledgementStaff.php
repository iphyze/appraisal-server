<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Bad Request: Only GET method is allowed', 405);
    }

    $userData = requireRoles(['super_admin', 'admin']);
    $role = authRoleKey($userData['role'] ?? '');
    $companyId = (int)($userData['company_id'] ?? 0);
    $companyScope = resolveCompanyScope($userData);
    $staffScope = (string)($userData['staff_scope'] ?? 'All');
    $cycleId = isset($_GET['cycle_id']) ? (int)$_GET['cycle_id'] : 0;

    if ($cycleId <= 0) {
        throw new Exception('Select an appraisal cycle first.', 400);
    }

    $cycleStmt = $conn->prepare('SELECT ac.id, ac.year, ac.title, ac.company_id, c.name AS company_name FROM appraisal_cycles ac INNER JOIN companies c ON c.id = ac.company_id WHERE ac.id = ? LIMIT 1');
    if (!$cycleStmt) throw new Exception('Database error: ' . $conn->error, 500);
    $cycleStmt->bind_param('i', $cycleId);
    $cycleStmt->execute();
    $cycle = $cycleStmt->get_result()->fetch_assoc();
    $cycleStmt->close();
    if (!$cycle) throw new Exception('Selected appraisal cycle was not found.', 404);
    if ($companyScope !== null && (int)$cycle['company_id'] !== (int)$companyScope) {
        throw new Exception('Unauthorized: Selected cycle is outside your company scope.', 403);
    }

    $where = [
        'ap.cycle_id = ?',
        "ap.status = 'Pending'",
        "COALESCE(ap.staff_email, u.email, '') <> ''",
        'u.is_active = 1',
        "LOWER(REPLACE(TRIM(subject_role.name), ' ', '_')) <> 'super_admin'",
    ];
    $params = [$cycleId];
    $types = 'i';

    if ($role === 'admin') {
        $where[] = 'ap.company_id = ?';
        $params[] = $companyId;
        $types .= 'i';
        if (in_array($staffScope, ['Local', 'Expatriate'], true)) {
            $where[] = "(LOWER(REPLACE(TRIM(subject_role.name), ' ', '_')) <> 'staff' OR ap.staff_type = ?)";
            $params[] = $staffScope;
            $types .= 's';
        }
    }

    $sql = "SELECT ap.id AS appraisal_id, ap.staff_user_id, ap.staff_fullname, ap.staff_email,
                   ap.staff_department AS department, ap.staff_job_title AS job_title, ap.staff_type,
                   ap.status, u.first_name, u.last_name, LOWER(REPLACE(TRIM(subject_role.name), ' ', '_')) AS role,
                   COALESCE(NULLIF(ap.staff_email, ''), u.email) AS email,
                   CONCAT(sup.first_name, ' ', sup.last_name) AS supervisor_name
            FROM appraisals ap
            INNER JOIN users u ON u.id = ap.staff_user_id
            INNER JOIN roles subject_role ON subject_role.id = u.role_id
            LEFT JOIN users sup ON sup.id = ap.supervisor_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY ap.staff_fullname ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Database error: ' . $conn->error, 500);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode([
        'status' => 'Success',
        'message' => 'Pending acknowledgement staff fetched successfully.',
        'data' => $rows,
        'meta' => ['count' => count($rows), 'cycle' => $cycle],
    ]);
} catch (Exception $e) {
    $code = (int)$e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
