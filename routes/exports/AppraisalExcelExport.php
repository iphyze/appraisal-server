<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/../utils/SimpleXlsx.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Bad Request: Only GET method is allowed', 405);

    $userData = requireRoles(['super_admin', 'admin', 'supervisor']);
    $role = strtolower(str_replace(' ', '_', trim((string)($userData['role'] ?? ''))));
    $userId = (int)($userData['id'] ?? 0);
    $companyId = (int)($userData['company_id'] ?? 0);
    $cycleId = (int)($_GET['cycle_id'] ?? 0);
    if ($cycleId <= 0) throw new Exception('Please select an appraisal cycle to export.', 400);

    $cycleStmt = $conn->prepare('SELECT id, year, title, company_id FROM appraisal_cycles WHERE id = ? LIMIT 1');
    $cycleStmt->bind_param('i', $cycleId);
    $cycleStmt->execute();
    $cycle = $cycleStmt->get_result()->fetch_assoc();
    $cycleStmt->close();
    if (!$cycle) throw new Exception('Selected appraisal cycle was not found.', 404);
    $companyScope = resolveCompanyScope($userData);
    if ($companyScope !== null && (int)$cycle['company_id'] !== (int)$companyScope) throw new Exception('Unauthorized: This cycle is outside the selected company scope.', 403);

    $where = ['ap.cycle_id = ?'];
    $types = 'i';
    $params = [$cycleId];

    if ($role === 'admin') {
        $where[] = 'ap.company_id = ?';
        $types .= 'i';
        $params[] = $companyId;
        $staffScope = trim((string)($userData['staff_scope'] ?? 'All'));
        if (in_array($staffScope, ['Local', 'Expatriate'], true)) {
            // Type scope applies to regular staff only; admin/supervisor appraisees
            // remain part of administrative reporting in their company.
            $where[] = "(NOT EXISTS (
                SELECT 1
                FROM users target_user
                INNER JOIN roles target_role ON target_role.id = target_user.role_id
                WHERE target_user.id = ap.staff_user_id
                  AND LOWER(REPLACE(TRIM(target_role.name), ' ', '_')) = 'staff'
            ) OR ap.staff_type = ?)";
            $types .= 's';
            $params[] = $staffScope;
        }
    } elseif ($role === 'supervisor') {
        $where[] = 'ap.supervisor_id = ?';
        $types .= 'i';
        $params[] = $userId;
    } else {
        $requestedType = trim((string)($_GET['staff_type'] ?? ''));
        if (in_array($requestedType, ['Local', 'Expatriate'], true)) {
            $where[] = 'ap.staff_type = ?';
            $types .= 's';
            $params[] = $requestedType;
        }
    }

    $whereSql = implode(' AND ', $where);
    $sql = "
        SELECT
            ap.staff_fullname,
            ap.staff_location,
            ap.staff_department,
            ap.staff_job_title,
            COALESCE(ap.date_of_joining, u.date_of_joining, '') AS date_of_joining,
            ac.year AS appraisal_year,
            COALESCE(ap.duration_years, '') AS duration_in_lambert,
            ap.development,
            ap.status_upgrade,
            ap.salary_upgrade,
            ap.evaluation_statement,
            COALESCE(a.section_avg, ap.kpi_rating) AS kpi_rating,
            b.section_avg AS work_accomplishment,
            cscore.section_avg AS work_attitude,
            d.section_avg AS work_competence,
            e.section_avg AS general_performance_competence,
            ap.appraisal_summary
        FROM appraisals ap
        INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id
        LEFT JOIN users u ON u.id = ap.staff_user_id
        LEFT JOIN users sup ON sup.id = ap.supervisor_id
        LEFT JOIN appraisal_section_scores a ON a.appraisal_id = ap.id AND a.section_code = 'A'
        LEFT JOIN appraisal_section_scores b ON b.appraisal_id = ap.id AND b.section_code = 'B'
        LEFT JOIN appraisal_section_scores cscore ON cscore.appraisal_id = ap.id AND cscore.section_code = 'C'
        LEFT JOIN appraisal_section_scores d ON d.appraisal_id = ap.id AND d.section_code = 'D'
        LEFT JOIN appraisal_section_scores e ON e.appraisal_id = ap.id AND e.section_code = 'E'
        WHERE {$whereSql}
        ORDER BY ap.staff_department ASC, ap.staff_fullname ASC
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Database error: ' . $conn->error, 500);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $headers = [
        'S/N', 'Fullname', 'Location', 'Department', 'Job Title', 'Date of Joining',
        'Appraisal Year', 'Duration In Lambert', 'Development', 'Status Upgrade', 'Salary Upgrade',
        'Evaluation Statement', 'KPI Rating * (30%)', 'Work Accomplishment * (25%)',
        'Work Attitude * (15%)', 'Work Competence * (15%)',
        'General Performance Competence * (15%)', 'Score'
    ];

    $rows = [];
    foreach ($records as $index => $row) {
        $rows[] = [
            $index + 1,
            $row['staff_fullname'],
            $row['staff_location'],
            $row['staff_department'],
            $row['staff_job_title'],
            $row['date_of_joining'],
            $row['appraisal_year'],
            $row['duration_in_lambert'],
            $row['development'],
            $row['status_upgrade'],
            $row['salary_upgrade'],
            $row['evaluation_statement'],
            $row['kpi_rating'],
            $row['work_accomplishment'],
            $row['work_attitude'],
            $row['work_competence'],
            $row['general_performance_competence'],
            $row['appraisal_summary'],
        ];
    }

    $suffix = $role === 'super_admin' && !empty($_GET['staff_type']) ? '_' . $_GET['staff_type'] : '';
    streamSimpleXlsx('Appraisals_' . $cycle['year'] . $suffix . '.xlsx', $headers, $rows, 'Appraisals ' . $cycle['year']);
} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
