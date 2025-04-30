<?php
// include("AuthController.php");
// class UserInformationController extends AuthController{
//     public function getUserProfile(){
//         $userData = $this->getBearerToken();
//         if($userData){
//             $userId = $userData['id'];
//             $con = $this->OpenDB();
//             $stmt = $con->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
//             $stmt->bind_param("i", $userId);
//             $stmt->execute();
//             $result = $stmt->get_result();
//             if($result && $result->num_rows > 0 ){
//                 $userProfile = $result->fetch_assoc();
//                 echo json_encode(array("status" => "success", "data" => $userProfile));
//                 return;
//             }else{
//                 http_response_code(404);
//                 echo json_encode(array("error" => "User not found"));
//                 return;
//             }
//         }else{
//             http_response_code(401);
//             echo json_encode(array("error" => "Unauthorized"));
//             return;
//         }
//     }
// }
?>