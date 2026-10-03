<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/../utils/Notifications.php';

header('Content-Type: application/json');

function resetOnboardingResponse($status, $message, $data = [], $code = 200)
{
    http_response_code($code);
    echo json_encode([
        'status'  => $status,
        'message' => $message,
        'data'    => $data,
    ]);
    exit;
}

function resetOnboardingColumnExists($conn, $table, $column)
{
    $safeTable  = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $table);
    $safeColumn = $conn->real_escape_string((string) $column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");

    return $result && $result->num_rows > 0;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new Exception('Bad Request: Only POST method is allowed.', 405);
    }

    $userData = requireRoles(['admin', 'super_admin']);

    $loggedInUserId    = (int) ($userData['id'] ?? 0);
    $loggedInRole      = authRoleKey($userData['role'] ?? '');
    $loggedInCompanyId = (int) ($userData['company_id'] ?? 0);

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = [];
    }

    if (!isset($data['supervisor_id']) || !is_numeric($data['supervisor_id'])) {
        throw new Exception("Field 'supervisor_id' is required.", 400);
    }

    if (!isset($data['cycle_id']) || !is_numeric($data['cycle_id'])) {
        throw new Exception("Field 'cycle_id' is required.", 400);
    }

    $supervisorId = (int) $data['supervisor_id'];
    $cycleId      = (int) $data['cycle_id'];

    if ($supervisorId <= 0 || $cycleId <= 0) {
        throw new Exception('Supervisor and appraisal cycle are required.', 400);
    }

    /**
     * Validate the target appraiser. Admins may only act inside their company;
     * super admins may act across companies.
     */
    $supStmt = $conn->prepare("
        SELECT
            u.id,
            u.first_name,
            u.last_name,
            u.email,
            u.company_id,
            u.is_active,
            r.name AS role_name
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = ?
          AND " . appraiserRoleWhere('r', 'u') . "
        LIMIT 1
    ");

    if (!$supStmt) {
        throw new Exception('Database error: ' . $conn->error, 500);
    }

    $supStmt->bind_param('i', $supervisorId);
    $supStmt->execute();
    $supervisor = $supStmt->get_result()->fetch_assoc();
    $supStmt->close();

    if (!$supervisor) {
        throw new Exception('Supervisor not found.', 404);
    }

    $supervisorCompanyId = (int) $supervisor['company_id'];

    if ($loggedInRole !== 'super_admin' && $supervisorCompanyId !== $loggedInCompanyId) {
        throw new Exception('Unauthorized: Supervisor does not belong to your company.', 403);
    }

    /**
     * The cycle must belong to the same company as the target supervisor.
     * A cycle is required explicitly so an administrator cannot accidentally
     * reset onboarding for the wrong appraisal year.
     */
    $cycleStmt = $conn->prepare("
        SELECT id, year, title, company_id, is_active
        FROM appraisal_cycles
        WHERE id = ?
          AND company_id = ?
        LIMIT 1
    ");

    if (!$cycleStmt) {
        throw new Exception('Database error: ' . $conn->error, 500);
    }

    $cycleStmt->bind_param('ii', $cycleId, $supervisorCompanyId);
    $cycleStmt->execute();
    $cycle = $cycleStmt->get_result()->fetch_assoc();
    $cycleStmt->close();

    if (!$cycle) {
        throw new Exception('Selected appraisal cycle was not found for this supervisor.', 404);
    }

    // Ensure notification infrastructure before beginning the transaction so
    // the CREATE TABLE guard cannot introduce an implicit commit mid-operation.
    ensureNotificationsTable($conn);

    $conn->begin_transaction();

    try {
        /**
         * Lock and confirm the exact cycle-specific onboarding record.
         */
        $onboardStmt = $conn->prepare("
            SELECT id, onboarded_at
            FROM supervisor_onboarding
            WHERE supervisor_id = ?
              AND cycle_id = ?
            LIMIT 1
            FOR UPDATE
        ");

        if (!$onboardStmt) {
            throw new Exception('Database error: ' . $conn->error, 500);
        }

        $onboardStmt->bind_param('ii', $supervisorId, $cycleId);
        $onboardStmt->execute();
        $onboarding = $onboardStmt->get_result()->fetch_assoc();
        $onboardStmt->close();

        if (!$onboarding) {
            throw new Exception(
                "This supervisor is already not onboarded for the {$cycle['year']} appraisal cycle.",
                409
            );
        }

        $onboardingId = (int) $onboarding['id'];
        $previousOnboardedAt = $onboarding['onboarded_at'] ?? null;

        $deleteStmt = $conn->prepare('DELETE FROM supervisor_onboarding WHERE id = ? LIMIT 1');
        if (!$deleteStmt) {
            throw new Exception('Database error: ' . $conn->error, 500);
        }

        $deleteStmt->bind_param('i', $onboardingId);
        $deleteStmt->execute();

        if ($deleteStmt->affected_rows !== 1) {
            $deleteStmt->close();
            throw new Exception('Unable to reset supervisor onboarding. Please try again.', 500);
        }
        $deleteStmt->close();

        /**
         * users.onboarded_at is retained only as a legacy compatibility field.
         * Recalculate it from any remaining cycle onboarding records instead of
         * blindly setting it to NULL, so historical compatibility stays correct.
         */
        if (resetOnboardingColumnExists($conn, 'users', 'onboarded_at')) {
            $legacyStmt = $conn->prepare("
                UPDATE users u
                SET u.onboarded_at = (
                    SELECT MAX(so.onboarded_at)
                    FROM supervisor_onboarding so
                    WHERE so.supervisor_id = u.id
                )
                WHERE u.id = ?
            ");

            if (!$legacyStmt) {
                throw new Exception('Database error: ' . $conn->error, 500);
            }

            $legacyStmt->bind_param('i', $supervisorId);
            $legacyStmt->execute();
            $legacyStmt->close();
        }

        /**
         * Audit exactly who reset onboarding and for which cycle.
         */
        $logStmt = $conn->prepare("
            INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        if (!$logStmt) {
            throw new Exception('Database error: ' . $conn->error, 500);
        }

        $action      = 'supervisor_onboarding_reset';
        $targetTable = 'supervisor_onboarding';
        $fullname    = trim(($supervisor['first_name'] ?? '') . ' ' . ($supervisor['last_name'] ?? ''));
        $description = "Onboarding reset for {$fullname} for the {$cycle['year']} appraisal cycle";

        $logStmt->bind_param(
            'iissis',
            $supervisorCompanyId,
            $loggedInUserId,
            $action,
            $targetTable,
            $supervisorId,
            $description
        );

        if (!$logStmt->execute()) {
            $error = $logStmt->error ?: $conn->error;
            $logStmt->close();
            throw new Exception('Unable to record onboarding reset audit: ' . $error, 500);
        }
        $logStmt->close();

        /**
         * Notify the affected supervisor/appraiser. Existing assignments and
         * appraisal records are intentionally not modified.
         */
        createNotification(
            $conn,
            $supervisorCompanyId,
            $supervisorId,
            'supervisor_onboarding_reset',
            'Appraisal onboarding reset',
            "Your onboarding for the {$cycle['year']} appraisal cycle was reset by an administrator. Complete onboarding again before starting or editing appraisals for this cycle.",
            '/appraisals?cycle_id=' . $cycleId
        );

        $conn->commit();

        resetOnboardingResponse('Success', "Supervisor onboarding reset for the {$cycle['year']} appraisal cycle.", [
            'supervisor_id'         => $supervisorId,
            'supervisor_name'       => $fullname,
            'cycle_id'              => $cycleId,
            'cycle_year'            => $cycle['year'],
            'cycle_title'           => $cycle['title'],
            'is_onboarded'          => 0,
            'previous_onboarded_at' => $previousOnboardedAt,
            'assignments_preserved' => true,
            'appraisals_preserved'  => true,
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
} catch (Throwable $e) {
    error_log('Reset Onboarding Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code <= 599) ? $code : 500;
    resetOnboardingResponse('Failed', $e->getMessage(), [], $code);
}
