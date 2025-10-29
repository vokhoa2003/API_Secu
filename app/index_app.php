<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');
ini_set('display_startup_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

ob_start();
header('Content-Type: application/json; charset=utf-8');

error_log("Received request: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']);

require_once __DIR__ . '/Controller/ApiController.php';
require_once __DIR__ . '/Controller/AuthController.php';
require_once __DIR__ . '/Encryption.php';

$encryption = new Encryption($_ENV['ENCRYPTION_KEY']);
$apiController = new ApiController();

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$action = $_POST['action'] ?? trim(str_replace('/API_Secu', '', $requestUri), '/');

$input = file_get_contents('php://input');
error_log("Raw input from php://input: " . $input);
if (!empty($input) && isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] === 'application/json') {
    $params = json_decode($input, true);
    error_log("Received JSON params: " . print_r($params, true));
} else {
    $params = $_POST;
    if (empty($params) && !empty($input)) {
        parse_str($input, $params);
        error_log("Parsed form params: " . print_r($params, true));
    } else {
        error_log("Received POST params: " . print_r($_POST, true));
    }
}
if (empty($params)) {
    error_log("No params received, checking GET params");
    $params = array_merge($params, $_GET);
    error_log("Received GET params: " . print_r($_GET, true));
}

if (strpos($requestUri, '/API_Secu') === 0) {
    if(!$action)
    {
        http_response_code(400);
        $result = ['error' => 'Endpoint not found'];
    }else{
        $result= $apiController->handleRequest($action,$params);
    }
} else {
    http_response_code(404);
    $result = ['error' => 'API endpoint must start with /API_Secu'];
}


// ✅ Chuẩn hóa kết quả thành List<Task> định dạng JSON
if (!is_array($result)) {
    $result = [$result];
}


// Đảm bảo mỗi phần tử là task, xử lý key và format datetime
foreach ($result as &$task) {
    if (!is_array($task)) continue;

    // ✅ Xử lý CreateDate - đảm bảo format đầy đủ yyyy-MM-dd HH:mm:ss
    if (isset($task['CreateDate'])) {
        $createDate = $task['CreateDate'];
        // Nếu chỉ có date (yyyy-MM-dd) thì thêm thời gian mặc định
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $createDate)) {
            $createDate .= ' 00:00:00';
        }
        $task['createDate'] = $createDate;
        unset($task['CreateDate']);
    }
    
    // ✅ Xử lý UpdateDate
    if (isset($task['UpdateDate'])) {
        $updateDate = $task['UpdateDate'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $updateDate)) {
            $updateDate .= ' 00:00:00';
        }
        $task['updateDate'] = $updateDate;
        unset($task['UpdateDate']);
    }
    
    // ✅ Xử lý BirthDate - chuyển null nếu là 0000-00-00 hoặc rỗng
    if (isset($task['BirthDate'])) {
        $birthDate = $task['BirthDate'];
        if (empty($birthDate) || $birthDate === '0000-00-00' || $birthDate === '0000-00-00 00:00:00') {
            $task['birthDate'] = null;
        } else {
            // Chỉ lấy phần date (bỏ time nếu có)
            $task['birthDate'] = substr($birthDate, 0, 10);
        }
        unset($task['BirthDate']);
    }

    // Đổi key sang camelCase
    if (isset($task['GoogleID'])) {
        $task['googleId'] = $task['GoogleID'];
        unset($task['GoogleID']);
    }
    if (isset($task['FullName'])) {
        $task['fullName'] = $task['FullName'];
        unset($task['FullName']);
    }
    if (isset($task['IdentityNumber'])) {
        $task['identityNumber'] = $task['IdentityNumber'];
        unset($task['IdentityNumber']);
    }
    if (isset($task['Phone'])) {
        $task['phone'] = $task['Phone'];
        unset($task['Phone']);
    }
    if (isset($task['Address'])) {
        $task['address'] = $task['Address'];
        unset($task['Address']);
    }
    if (isset($task['Status'])) {
        $task['status'] = $task['Status'];
        unset($task['Status']);
    }
    if (isset($task['Question'])) {
        $task['question'] = $task['Question'];
        unset($task['Question']);
    }
    if (isset($task['Answer'])) {
        $task['answer'] = $task['Answer'];
        unset($task['Answer']);
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;

?>