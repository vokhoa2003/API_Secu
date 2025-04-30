<?php
// include("../app/Controller/StudentController.php");

// header("Content-Type: application/json; charset=UTF-8");

// $controller = new StudentController;
// //$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
// $action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

// switch ($action) {
//     case 'get':
//         //$geturl = json_decode(file_get_contents("php://input"), true, 512, JSON_UNESCAPED_UNICODE); Lỗi phiên bản, ko dùng
//         //$geturl = json_decode(utf8_encode(file_get_contents("php://input")), true);
//         $geturl = json_decode(mb_convert_encoding(file_get_contents("php://input"), 'UTF-8', 'auto'), true);
//         $masv = isset($geturl['masv']) ? $geturl['masv'] : null;
//         $hodem = isset($geturl['hodem']) ? $geturl['hodem'] : null;
//         $ten = isset($geturl['ten']) ? $geturl['ten'] : null;
//         $lop = isset($geturl['lop']) ? $geturl['lop'] : null;
//         $data = $controller->Get($masv, $hodem, $ten, $lop);
//         $result = array();
//         if ($data) {
//             while($row = mysqli_fetch_assoc($data)){
//                 $result[] = $row;
//             }
//         }
//         echo json_encode($result);
//         break;

//     case 'add':
//         $geturl = json_decode(mb_convert_encoding(file_get_contents("php://input"), 'UTF-8', 'auto'), true);
//         $masv = isset($geturl['masv']) ? $geturl['masv'] : null;
//         $hodem = isset($geturl['hodem']) ? $geturl['hodem'] : null;
//         $ten = isset($geturl['ten']) ? $geturl['ten'] : null;
//         $lop = isset($geturl['lop']) ? $geturl['lop'] : null;
//         echo json_encode($masv);;
//         if ($controller->AddStudent($masv, $hodem, $ten, $lop)) {
//             echo json_encode(array("message" => "Thêm thành công"));
//         } else {
//             echo json_encode(array("message" => "Thêm thất bại"));
//         }
//         break;

//     case 'update':
//         $geturl = json_decode(mb_convert_encoding(file_get_contents("php://input"), 'UTF-8', 'auto'), true);
//         $masv = isset($geturl['masv']) ? $geturl['masv'] : null;
//         $new_masv = isset($geturl['new_masv']) ? $geturl['new_masv'] : "";
//         $hodem = isset($geturl['hodem']) ? $geturl['hodem'] : null;
//         $ten = isset($geturl['ten']) ? $geturl['ten'] : null;
//         $lop = isset($geturl['lop']) ? $geturl['lop'] : null;
//         echo json_encode($masv);
//         if ($controller->UpdateStudent($masv, $new_masv, $hodem, $ten, $lop)) {
//             echo json_encode(array("message" => "Cập nhật thành công"));
//         } else {
//             echo json_encode(array("message" => "Cập nhật thất bại"));
//         }
//         break;

//     case 'delete':
//         $masv = $_POST['masv'];

//         if ($controller->DeleteStudent($masv)) {
//             echo json_encode(array("message" => "Xóa thành công"));
//         } else {
//             echo json_encode(array("message" => "Xóa thất bại"));
//         }
//         break;

//     default:
//         echo json_encode(array("message" => "Hành động không hợp lệ"));
//         break;
// }
?>
