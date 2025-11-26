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
    if (!$action) {
        http_response_code(400);
        $result = ['error' => 'Endpoint not found'];
    } else {
        $result = $apiController->handleRequest($action, $params);
    }
} else {
    http_response_code(404);
    $result = ['error' => 'API endpoint must start with /API_Secu'];
}

// ✅ Chuẩn hóa kết quả thành List<Object> định dạng JSON
if (!is_array($result)) {
    $result = [$result];
}

// ✅ PHÂN BIỆT MODEL: teacher vs account (Task)
//CHUẨN HÓA Chuyển PascalCase (DB) → camelCase (Java)
foreach ($result as &$item) {
    if (!is_array($item)) continue;

    // Xác định loại model
    $isTeacher = (isset($item['IdAccount']) || isset($item['ClassId']));
    $isAccount = (isset($item['email']) || isset($item['FullName']));

    // CHUẨN HÓA CHO MODEL TEACHER
    if ($isTeacher) {
        if (isset($item['Id'])) {
            $item['id'] = $item['Id'];
            unset($item['Id']);
        }
        if (isset($item['IdAccount'])) {
            $item['idAccount'] = $item['IdAccount'];
            unset($item['IdAccount']);
        }
        if (isset($item['Name'])) {
            $item['name'] = $item['Name'];
            unset($item['Name']);
        }
        if (isset($item['ClassId'])) {
            $item['classId'] = $item['ClassId'];
            unset($item['ClassId']);
        }
    }

    // CHUẨN HÓA CHO MODEL ACCOUNT / TASK
    if ($isAccount) {
        if (isset($item['FullName'])) {
            $item['fullName'] = $item['FullName'];
            unset($item['FullName']);
        }
        if (isset($item['GoogleID'])) {
            $item['googleId'] = $item['GoogleID'];
            unset($item['GoogleID']);
        }
        if (isset($item['IdentityNumber'])) {
            $item['identityNumber'] = $item['IdentityNumber'];
            unset($item['IdentityNumber']);
        }
        if (isset($item['Phone'])) {
            $item['phone'] = $item['Phone'];
            unset($item['Phone']);
        }
        if (isset($item['Address'])) {
            $item['address'] = $item['Address'];
            unset($item['Address']);
        }
        if (isset($item['Status'])) {
            $item['status'] = $item['Status'];
            unset($item['Status']);
        }
        if (isset($item['Question'])) {
            $item['question'] = $item['Question'];
            unset($item['Question']);
        }
        if (isset($item['Answer'])) {
            $item['answer'] = $item['Answer'];
            unset($item['Answer']);
        }
    }

    // XỬ LÝ NGÀY GIỜ -> cũng chuyển sang camelCase -> Tránh lỗi parsing date ở Java
    if (isset($item['CreateDate'])) {
        $createDate = $item['CreateDate'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $createDate)) {
            $createDate .= ' 00:00:00';
        }
        $item['createDate'] = $createDate;
        unset($item['CreateDate']);
    }

    if (isset($item['UpdateDate'])) {
        $updateDate = $item['UpdateDate'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $updateDate)) {
            $updateDate .= ' 00:00:00';
        }
        $item['updateDate'] = $updateDate;
        unset($item['UpdateDate']);
    }

    if (isset($item['BirthDate'])) {
        $birthDate = $item['BirthDate'];
        if (empty($birthDate) || $birthDate === '0000-00-00' || $birthDate === '0000-00-00 00:00:00') {
            $item['birthDate'] = null;
        } else {
            $item['birthDate'] = substr($birthDate, 0, 10);
        }
        unset($item['BirthDate']);
    }
}
//kết thúc chuẩn hóa

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
?>