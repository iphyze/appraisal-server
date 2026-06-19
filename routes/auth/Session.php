<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/AuthSecurity.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Bad Request: Only GET method is allowed.', 405);
    }

    $user = authenticateUser();

    /*
     * Reuse the current browser CSRF token when it is still valid. Rotating it
     * on every session probe makes another open tab's in-memory token stale.
     */
    $csrfToken = currentOrIssueCsrfToken();

    unset($user['token_version']);

    echo json_encode([
        'status'  => 'Success',
        'message' => 'Session is valid.',
        'data'    => array_merge($user, [
            'csrf_token' => $csrfToken,
        ]),
    ]);
} catch (Exception $e) {
    $code = (int) $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 401;

    http_response_code($code);

    echo json_encode([
        'status'  => 'Failed',
        'message' => $e->getMessage(),
    ]);
}
