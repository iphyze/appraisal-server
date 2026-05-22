<?php
require 'vendor/autoload.php';
require_once 'includes/AuthSecurity.php';
header('Content-Type: application/json');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Bad Request: Only POST method is allowed', 405);
    requireCsrfForMutation();
    clearAuthCookies();
    echo json_encode(['status' => 'Success', 'message' => 'You have been signed out.']);
} catch (Exception $e) {
    clearAuthCookies();
    $code = (int)$e->getCode(); $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
