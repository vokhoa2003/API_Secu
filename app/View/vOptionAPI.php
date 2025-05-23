<?php
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
echo json_encode(['encrypted' => $encryptedResult], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
// echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
// include("../app/Controller/DataController.php");
// include("../app/Controller/AuthController.php");

// header("Content-Type: application/json; charset=UTF-8");

// $controller = new DataController;
// $authController = new AuthController;
// //$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
// $action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

// switch ($action) {
//     case 'get':
//         //$geturl = json_decode(file_get_contents("php://input"), true, 512, JSON_UNESCAPED_UNICODE); Lỗi phiên bản, ko dùng
//         //$geturl = json_decode(utf8_encode(file_get_contents("php://input")) , true);
//         $geturl = json_decode(mb_convert_encoding(file_get_contents("php://input"), 'UTF-8', 'auto'), true);
//         $id = isset($geturl['id']) ? $geturl['id'] : null;
//         $google_id = isset($geturl['google_id']) ? $geturl['google_id'] : null;
//         $email = isset($geturl['email']) ? $geturl['email'] : null;
//         $full_name = isset($geturl['full_name']) ? $geturl['full_name'] : null;
//         $data = $controller->GetUserData($id, $google_id, $email, $full_name);
//         $result = array();
//         if ($data) {
//             while($row = mysqli_fetch_assoc($data)){
//                 $result[] = $row;
//             }
//         }
//         if($controller->GetUserData($id, $google_id, $email, $full_name) == null){
//             echo json_encode(array("message" => "Không có dữ liệu"));
//         }else{
//             echo json_encode($result);
//         }
//         break;
//     case 'add':
//         $geturl = json_decode(mb_convert_encoding(file_get_contents("php://input"), 'UTF-8', 'auto'), true);
//         $google_id = isset($geturl['google_id']) ? $geturl['google_id'] : null;
//         $email = isset($geturl['email']) ? $geturl['email'] : null;
//         $full_name = isset($geturl['full_name']) ? $geturl['full_name'] : null;

//         if ($google_id && $email && $full_name) {
//             // Kiểm tra người dùng đã tồn tại
//             $user = $authController->GetUserIdByGoogleId($google_id);
//             if (!$user) {
//                 // Thêm người dùng mới
//                 if ($controller->AddUser($google_id, $email, $full_name)) {
//                     // Tạo JWT
//                     $user = $authController->GetUserIdByGoogleId($google_id);
//                     $token = $authController->LoginWithGoogle($google_id);
//                     echo json_encode([
//                         'status' => 'success',
//                         'token' => $token['token'],
//                         'message' => 'Thêm thành công'
//                     ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
//                 } else {
//                     echo json_encode(['message' => 'Thêm thất bại'], JSON_PRETTY_PRINT);
//                 }
//             } else {
//                 // Người dùng đã tồn tại, tạo JWT
//                 $token = $authController->LoginWithGoogle($google_id);
//                 echo json_encode([
//                     'status' => 'success',
//                     'token' => $token['token'],
//                     'message' => 'Người dùng đã tồn tại, đăng nhập thành công'
//                 ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
//             }
//         } else {
//             echo json_encode(['message' => 'Thiếu thông tin người dùng'], JSON_PRETTY_PRINT);
//         }
//         break;
//     case 'update':
//         $geturl = json_decode(mb_convert_encoding(file_get_contents("php://input"), 'UTF-8', 'auto'), true);
//         $id = isset($geturl['id']) ? $geturl['id'] : null;
//         $google_id = isset($geturl['google_id']) ? $geturl['google_id'] : null;
//         $email = isset($geturl['email']) ? $geturl['email'] : null;
//         $full_name = isset($geturl['full_name']) ? $geturl['full_name'] : null;
//         if ($controller->UpdateUser($id, $google_id, $email, $full_name)) {
//             echo json_encode(array("message" => "Cập nhật thành công"));
//         } else {
//             echo json_encode(array("message" => "Cập nhật thất bại"));
//         }
//         break;
//     case 'delete':
//         $geturl = json_decode(mb_convert_encoding(file_get_contents("php://input"), 'UTF-8', 'auto'), true);
//         $id = isset($geturl['id']) ? $geturl['id'] : null;
//         if ($controller->DeleteUser($id)) {
//             echo json_encode(array("message" => "Xóa thành công"));
//         } else {
//             echo json_encode(array("message" => "Xóa thất bại"));
//         }
//     break;
//     default:
//         echo json_encode(['error' => 'Hành động không hợp lệ'], JSON_PRETTY_PRINT);
//     break;
// }
?>