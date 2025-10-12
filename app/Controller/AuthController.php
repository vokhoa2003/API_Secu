<?php
require_once __DIR__ . '/../Model/mSQL.php';
require_once __DIR__ . "/../../JwtHandler.php";
require_once __DIR__ . "/../Middleware/AuthMiddleware.php";
class AuthController{
    private $modelSQL;
    private $jwtHandler;

    public function __construct() {
        $this->modelSQL = new ModelSQL();
        $this->jwtHandler = new JwtHandler();
    }
    public function LoginWithGoogle($googleId){

        if(!isset($googleId) || empty($googleId)){
            // http_response_code(400);
            // echo json_encode(array("error" => "Missing google_id"));
            // exit;
            return ["error" => "Missing google_id"];
            exit;
        }
        // $googleId = $_POST["google_id"];
        $user = $this->GetUserIdByGoogleId($googleId);
        
        if(!$user){
            http_response_code(401);
            // echo json_encode(array("error" => "User not found"));
            return ["error" => "User not found"];
            exit;
        }
        //print_r($user_id);
        //$jwtHanlder = new JwtHandler;
        if(isset($user['Status']) && $user['Status'] == 'Blocked'){
            http_response_code(401);
            // echo json_encode(array("error" => "User is inactive"));
            return ["error" => "User is inactive"];
            exit;
        }
        $token = $this->jwtHandler->createToken($user['email'], $user['role'], $user['id'], $user['FullName']);
        error_log("Generated token: " . ($token ?? 'Null'));
        // json_encode(array("token" => $token));
        if(!isset($token) || empty($token)){
            http_response_code(500);
            return ["error" => "Token generation failed"];
            exit;
        }
        return ["status" => "success", "token" => $token];
        
    }
    public function GetUserIdByGoogleId($google_id, $table = 'account') {
        // Có thể truyền bảng từ ApiController
        $result = $this->modelSQL->ViewData($table, ['GoogleID' => $google_id]);
        if ($result && $row = $result->fetch_assoc()) {
            error_log("GetUserIdByGoogleId found: " . print_r($row, true));
            return $row;
        }
        error_log("GetUserIdByGoogleId: No user found for GoogleID $google_id");
        return null;
    }
    public function getBearerToken(){
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            http_response_code(401);
            echo json_encode(["error" => "Missing Authorization header"], JSON_PRETTY_PRINT);
            exit;
        }

        $authHeader = $headers['Authorization'];
        if (strpos($authHeader, "Bearer ") !== 0) {
            http_response_code(401);
            echo json_encode(["error" => "Invalid Authorization header format"], JSON_PRETTY_PRINT);
            exit;
        }

        $token = substr($authHeader, 7);
        $jwtHandler = new JwtHandler;
        $userData = $jwtHandler->verifyToken($token);
        if (!$userData) {
            http_response_code(401);
            echo json_encode(["error" => "Invalid or expired token"], JSON_PRETTY_PRINT);
            exit;
        }

        return $userData['data'];
    }
}
?>