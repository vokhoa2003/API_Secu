<?php
/**
 * Debug script - Kiểm tra user_tokens trong database
 * Usage: http://localhost/API_Secu/debug-db.php?google_id=xxx
 */

require_once __DIR__ . '/app/Model/mConnect.php';

header('Content-Type: application/json; charset=utf-8');

$google_id = $_GET['google_id'] ?? null;
$email = $_GET['email'] ?? null;

$conn = new Connect();
$db = $conn->openDB();

$result = [
    'timestamp' => date('Y-m-d H:i:s'),
    'request' => [
        'google_id' => $google_id,
        'email' => $email
    ],
    'data' => []
];

// 1. Kiểm tra account
if ($email) {
    $stmt = $db->prepare("SELECT id, email, GoogleID, role, Status FROM account WHERE email = ?");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result();
    $result['data']['account'] = $res->fetch_assoc();
    $stmt->close();
    
    if ($result['data']['account']) {
        $google_id = $result['data']['account']['GoogleID'];
    }
}

// 2. Kiểm tra user_tokens
if ($google_id) {
    $stmt = $db->prepare("SELECT * FROM user_tokens WHERE google_id = ?");
    $stmt->bind_param('s', $google_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $result['data']['user_tokens'] = $res->fetch_assoc();
    $stmt->close();
    
    // 3. Check expiration
    if ($result['data']['user_tokens']) {
        $expiresAt = strtotime($result['data']['user_tokens']['expires_at']);
        $now = time();
        $result['data']['token_status'] = [
            'expires_at' => $result['data']['user_tokens']['expires_at'],
            'current_time' => date('Y-m-d H:i:s', $now),
            'is_expired' => $expiresAt < $now,
            'time_remaining' => $expiresAt - $now,
            'status' => $result['data']['user_tokens']['Status']
        ];
    }
}

// 4. List all tokens
$stmt = $db->query("SELECT google_id, Status, expires_at, created_at FROM user_tokens ORDER BY created_at DESC LIMIT 10");
$result['data']['all_tokens'] = [];
while ($row = $stmt->fetch_assoc()) {
    $result['data']['all_tokens'][] = $row;
}
$stmt->close();

$db->close();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
