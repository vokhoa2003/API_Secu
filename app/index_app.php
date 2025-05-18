<?php
header('Content-Type: application/json; charset=utf-8');

error_log("Received request: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']);

require_once __DIR__ . '/Controller/ApiController.php';
require_once __DIR__ . '/Controller/AuthController.php';
require_once __DIR__ . '/Encryption.php';

$encryption = new Encryption($_ENV['ENCRYPTION_KEY']);
$apiController = new ApiController();

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$action = $_GET['action'] ?? trim(str_replace('/API_Secu', '', $requestUri), '/');

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

    // Chuyển thời gian sang định dạng ISO 8601
    if (isset($task['CreateDate'])) {
        // $task['createDate'] = date(DATE_ISO8601, strtotime($task['CreateDate']));
        $task['createDate'] = $task['CreateDate'];
        unset($task['CreateDate']);
    }
    if (isset($task['UpdateDate'])) {
        //$task['updateDate'] = date(DATE_ISO8601, strtotime($task['UpdateDate']));
        $task['updateDate'] = $task['UpdateDate'];
        unset($task['UpdateDate']);
    }
    if (isset($task['BirthDate']) && $task['BirthDate'] !== null) {
        //$task['birthDate'] = date(DATE_ISO8601, strtotime($task['BirthDate']));
        $task['birthDate'] = $task['BirthDate'];
        unset($task['BirthDate']);
    } else {
        $task['birthDate'] = null;
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
    // Các field khác giữ nguyên như: id, email, phone, address
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;

// header('Content-Type: application/json; charset=utf-8');

// error_log("Received request: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']);

// // Include các file cần thiết
// require_once __DIR__ . '/Controller/ApiController.php';
// require_once __DIR__ . '/Controller/AuthController.php';
// require_once __DIR__ . '/Encryption.php';
// $encryption = new Encryption($_ENV['ENCRYPTION_KEY']);
// // Khởi tạo controller
// $apiController = new ApiController();

// // Lấy URL path
// $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// $action = $_GET['action'] ?? trim(str_replace('/API_Secu', '', $requestUri), '/');

// // Lấy dữ liệu từ body và $_POST
// $input = file_get_contents('php://input');
// error_log("Raw input from php://input: " . $input);
// if (!empty($input) && isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] === 'application/json') {
//     $params = json_decode($input, true);
//     error_log("Received JSON params: " . print_r($params, true));
// } else {
//     $params = $_POST;
//     if (empty($params) && !empty($input)) {
//         parse_str($input, $params);
//         error_log("Parsed form params: " . print_r($params, true));
//     } else {
//         error_log("Received POST params: " . print_r($_POST, true));
//     }
// }

// if (empty($params)) {
//     error_log("No params received, checking GET params");
//     $params = array_merge($params, $_GET);
//     error_log("Received GET params: " . print_r($_GET, true));
// }

// // Xử lý các yêu cầu
// if (strpos($requestUri, '/API_Secu') === 0) {
//     if ($action === 'login') {
//         $result = $apiController->handleRequest('login', $params);
//     } elseif ($action === 'get') {
//         $result = $apiController->handleRequest('get', $params);
//     } elseif ($action === 'add') {
//         $result = $apiController->handleRequest('add', $params);
//     } elseif ($action === 'update') {
//         $result = $apiController->handleRequest('update', $params);
//     } elseif ($action === 'delete') {
//         $result = $apiController->handleRequest('delete', $params);
//     } elseif ($action === 'refresh_token') {
//         $result = $apiController->handleRequest('refresh_token', $params);
//     } elseif ($action === 'logout') {
//         $result = $apiController->handleRequest('logout', $params);
//     } else {
//         http_response_code(404);
//         $result = ['error' => 'Endpoint not found'];
//     }
// } else {
//     http_response_code(404);
//     $result = ['error' => 'API endpoint must start with /API_Secu'];
// }

// echo json_encode($result, JSON_PRETTY_PRINT);
// exit;
?>