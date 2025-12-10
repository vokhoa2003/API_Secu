<?php
error_reporting(0);
ini_set('display_errors', 0);
header("Content-Type: application/json; charset=UTF-8");

try {
    require_once __DIR__ . '/../Controller/ApiController.php';
    require_once __DIR__ . '/../../config/env.php';
    require_once __DIR__ . '/../Encryption.php';
    
    $encryption = new Encryption($_ENV['ENCRYPTION_KEY']);
    $apiController = new ApiController;
    
    $action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
    $inputData = json_decode(mb_convert_encoding(file_get_contents("php://input"), 'UTF-8', 'auto'), true) ?? [];
    
    error_log("API Request: action=" . $action . ", input=" . json_encode($inputData));
    
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
    
    error_log("API Params: " . json_encode($params));
    
    $result = $apiController->handleRequest($action, $params);
    
    error_log("API Result: " . json_encode($result));
    
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("API Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error',
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
?>