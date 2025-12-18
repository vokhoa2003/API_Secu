<?php
error_reporting(0);
ini_set('display_errors', 0);
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/../Controller/ApiController.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../Encryption.php';
$encryption = new Encryption($_ENV['ENCRYPTION_KEY']);
$apiController = new ApiController;
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
$inputData = json_decode(mb_convert_encoding(file_get_contents("php://input"), 'UTF-8', 'auto'), true) ?? [];
if (isset($inputData['encrypted'])) {
    // Giải mã dữ liệu đầu vào
    $params = $encryption->decrypt($inputData['encrypted']);
    if ($params === null) {
        echo json_encode(['error' => 'Failed to decrypt input data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    $params = $inputData; // Nếu không có trường encrypted, giữ nguyên dữ liệu (trường hợp không mã hóa)
}
// echo json_encode($params);
// exit;
$result = $apiController->handleRequest($action, $params);
$encryptedResult = $encryption->encrypt($result);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
//echo json_encode(['encrypted' => $encryptedResult], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
