<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json; charset=UTF-8');

function gsLike($value): string
{
    return '%' . trim((string) $value) . '%';
}

function gsPush(array &$rows, string $type, string $group, $id, string $title, string $subtitle, string $url): void
{
    if (!$id || trim($title) === '') {
        return;
    }

    $rows[] = [
        'key' => $type . '-' . (int) $id,
        'type' => $type,
        'group' => $group,
        'id' => (int) $id,
        'title' => $title,
        'subtitle' => $subtitle,
        'url' => $url,
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Bad Request: Only GET method is allowed', 405);
    }

    $userData = authenticateUser();
    $loggedInUserId = (int) ($userData['id'] ?? 0);
    $loggedInRole = authRoleKey($userData['role'] ?? '');
    $companyScope = resolveCompanyScope($userData);
    $q = trim((string) ($_GET['q'] ?? ''));

    if (strlen($q) < 2) {
        echo json_encode(['status' => 'Success', 'message' => 'Search query too short.', 'data' => []]);
        exit;
    }

    $like = gsLike($q);
    $rows = [];
    $isAdminLike = in_array($loggedInRole, ['super_admin', 'admin'], true);

    if ($isAdminLike) {
        $whereCompany = $companyScope !== null ? ' AND u.company_id = ?' : '';
        $userSql = "
            SELECT u.id, u.first_name, u.last_name, u.email, u.department, u.job_title,
                   r.name AS role_name, c.code AS company_code, c.name AS company_name
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            INNER JOIN companies c ON c.id = u.company_id
            WHERE u.is_active = 1
              AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.department LIKE ? OR u.job_title LIKE ?)
              {$whereCompany}
            ORDER BY u.first_name ASC, u.last_name ASC
            LIMIT 8
        ";
        $stmt = $conn->prepare($userSql);
        if (!$stmt) {
            throw new Exception('Database error: ' . $conn->error, 500);
        }
        if ($companyScope !== null) {
            $stmt->bind_param('sssssi', $like, $like, $like, $like, $like, $companyScope);
        } else {
            $stmt->bind_param('sssss', $like, $like, $like, $like, $like);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $roleKey = authRoleKey($row['role_name'] ?? 'user');
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $isAppraiser = in_array($roleKey, ['admin', 'supervisor'], true);
            $subtitle = trim(($row['job_title'] ?? ucfirst($roleKey)) . ' • ' . ($row['department'] ?? $row['email'] ?? ''));
            if ($loggedInRole === 'super_admin' && $companyScope === null) {
                $subtitle .= ' • ' . ($row['company_code'] ?? $row['company_name']);
            }
            gsPush(
                $rows,
                $isAppraiser ? 'appraiser' : 'user',
                $isAppraiser ? 'Appraisers' : 'Users',
                $row['id'],
                $name,
                $subtitle,
                $isAppraiser ? '/supervisors/view/' . $row['id'] : '/users/view/' . $row['id']
            );
        }
        $stmt->close();
    }

    // A supervisor or an administrator can be an assigned appraiser.
    if (in_array($loggedInRole, ['supervisor', 'admin'], true)) {
        $staffSql = "
            SELECT DISTINCT u.id, u.first_name, u.last_name, u.department, u.job_title, sa.cycle_id
            FROM supervisor_assignments sa
            INNER JOIN users u ON u.id = sa.staff_id
            INNER JOIN roles ur ON ur.id = u.role_id
            WHERE sa.supervisor_id = ?
              AND " . appraiseeRoleWhere('ur') . "
              AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.department LIKE ? OR u.job_title LIKE ?)
            ORDER BY u.first_name ASC, u.last_name ASC
            LIMIT 8
        ";
        $stmt = $conn->prepare($staffSql);
        if (!$stmt) {
            throw new Exception('Database error: ' . $conn->error, 500);
        }
        $stmt->bind_param('isssss', $loggedInUserId, $like, $like, $like, $like, $like);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            gsPush($rows, 'employee', 'Assigned Employees', $row['id'], $name, trim(($row['job_title'] ?? 'Employee') . ' • ' . ($row['department'] ?? 'No department')), '/appraisals/create/' . $row['id'] . '/' . $row['cycle_id']);
        }
        $stmt->close();
    }

    // All appraisees can find their own records; appraisers can also find records they conducted.
    if (in_array($loggedInRole, ['staff', 'supervisor', 'admin'], true)) {
        $appraisalAccess = $loggedInRole === 'staff'
            ? 'ap.staff_user_id = ?'
            : '(ap.staff_user_id = ? OR ap.supervisor_id = ?)';
        $aprSql = "
            SELECT ap.id, ap.status, ap.appraisal_summary, ac.title, ac.year,
                   CONCAT(st.first_name, ' ', st.last_name) AS staff_name
            FROM appraisals ap
            INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id
            LEFT JOIN users st ON st.id = ap.staff_user_id
            WHERE {$appraisalAccess}
              AND (ac.title LIKE ? OR ac.year LIKE ? OR ap.status LIKE ? OR st.first_name LIKE ? OR st.last_name LIKE ?)
            ORDER BY ap.id DESC
            LIMIT 8
        ";
        $stmt = $conn->prepare($aprSql);
        if (!$stmt) {
            throw new Exception('Database error: ' . $conn->error, 500);
        }
        if ($loggedInRole === 'staff') {
            $stmt->bind_param('isssss', $loggedInUserId, $like, $like, $like, $like, $like);
        } else {
            $stmt->bind_param('iisssss', $loggedInUserId, $loggedInUserId, $like, $like, $like, $like, $like);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $ownRecord = $loggedInRole === 'staff' || trim((string) $row['staff_name']) === trim(($userData['first_name'] ?? '') . ' ' . ($userData['last_name'] ?? ''));
            $title = $ownRecord
                ? ($row['title'] . ' (' . $row['year'] . ')')
                : ($row['staff_name'] . ' • ' . $row['year']);
            gsPush($rows, 'appraisal', 'Appraisals', $row['id'], $title, trim(($row['status'] ?? 'Pending') . ' • Score: ' . ($row['appraisal_summary'] ?? '—')), '/appraisals/view/' . $row['id']);
        }
        $stmt->close();
    }

    if ($isAdminLike) {
        $cycleCompany = $companyScope !== null ? ' AND company_id = ?' : '';
        $cycleSql = "SELECT id, title, year, is_active FROM appraisal_cycles WHERE (title LIKE ? OR year LIKE ?) {$cycleCompany} ORDER BY year DESC LIMIT 5";
        $stmt = $conn->prepare($cycleSql);
        if (!$stmt) {
            throw new Exception('Database error: ' . $conn->error, 500);
        }
        if ($companyScope !== null) {
            $stmt->bind_param('ssi', $like, $like, $companyScope);
        } else {
            $stmt->bind_param('ss', $like, $like);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            gsPush($rows, 'cycle', 'Cycles', $row['id'], $row['title'] . ' (' . $row['year'] . ')', ((int) $row['is_active'] === 1 ? 'Active cycle' : 'Appraisal cycle'), '/cycles/view/' . $row['id']);
        }
        $stmt->close();
    }

    echo json_encode(['status' => 'Success', 'message' => 'Search results fetched successfully', 'data' => array_slice($rows, 0, 20)]);
    exit;
} catch (Throwable $e) {
    $statusCode = (int) $e->getCode();
    http_response_code($statusCode >= 400 && $statusCode <= 599 ? $statusCode : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
    exit;
}
