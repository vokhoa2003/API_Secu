<?php
//include("../../JwtHandler.php");
require __DIR__ . "/../../JwtHandler.php";
class AuthMiddleware{
    public static function verifyRequest($action){
        $protectedAction = [ 'update', 'delete', 'logout', 'add', 'AdminUpdate'];
        if(in_array($action, $protectedAction)){
            $headers = getallheaders();
            if(!isset($headers["Authorization"])){
                http_response_code(401);
                return json_encode(array("error" => "Missing Authorization header"));
            }
            $authHeader = $headers["Authorization"];
            if (strpos($authHeader, "Bearer ") !== 0) {
                http_response_code(401);
                echo json_encode(["error" => "Invalid Authorization header format"]);
                exit;
            }
            $parts = explode(" ", $authHeader, 2);
            if (count($parts) !== 2 || $parts[0] !== "Bearer") {
                http_response_code(401);
                echo json_encode(["error" => "Invalid Authorization header format"]);
                exit;
            }
            $token = $parts[1];
            if (empty($token)) {
                http_response_code(401);
                echo json_encode(array("error" => "Missing token"));
                exit;
            }
            $jwtHandler = new JwtHandler;
            $result = $jwtHandler->verifyToken($token);
            if($result === null){
                http_response_code(401);
                echo json_encode(array("error" => "Invalid or expired token"));
                exit;
            }

            // if (($action === 'delete' || $action === 'AdminUpdate' || $action === 'add') && $result['data']['role'] !== 'admin') {
            //     http_response_code(403);
            //     echo json_encode(['error' => 'Only admins can do this action'], JSON_PRETTY_PRINT);
            //     exit;
            // }
            return $result['data'];
        }
        return null;
    }
}
?>