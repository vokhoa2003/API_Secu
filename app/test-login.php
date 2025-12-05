<?php
// API_Secu/app/test-login.php
// CHỈ DÙNG CHO TESTING ZAP - XÓA KHI DEPLOY PRODUCTION

header('Content-Type: application/json; charset=utf-8');

// Allow CORS for ZAP
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../src/Controller/AuthController.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['google_id']) || empty($input['google_id'])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing google_id"]);
    exit;
}

$authController = new AuthController();
$result = $authController->LoginWithGoogle($input['google_id']);

if (isset($result['error'])) {
    http_response_code(401);
    echo json_encode($result);
    exit;
}

// Generate CSRF token
$csrfToken = bin2hex(random_bytes(32));

// Set cookies
if (isset($result['token'])) {
    $secureFlag = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    
    // Set auth_token cookie
    setcookie('auth_token', $result['token'], [
        'expires' => time() + 3600,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'],
        'secure' => $secureFlag,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    // Set csrf_token cookie
    setcookie('csrf_token', $csrfToken, [
        'expires' => time() + 3600,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'],
        'secure' => $secureFlag,
        'httponly' => false, // Allow JS to read for double-submit CSRF
        'samesite' => 'Strict'
    ]);
    
    // Also start session and store CSRF
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['csrf_token'] = $csrfToken;
    
    echo json_encode([
        "status" => "success",
        "message" => "Authentication successful for ZAP testing",
        "token" => $result['token'],
        "csrf_token" => $csrfToken
    ]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Token generation failed"]);
}
?>