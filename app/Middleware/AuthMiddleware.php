<?php
//include("../../JwtHandler.php");
require __DIR__ . "/../../JwtHandler.php";
class AuthMiddleware{
    public static function verifyRequest($action, $params = []){
        $protectedAction = ['update', 'delete', 'logout', 'add', 'AdminUpdate', 'get', 'autoGet','autoUpdate', 'AdminUpdate', 'muitiInsert', 'refresh_token'];
        if(in_array($action, $protectedAction)){
            // Special case: refresh_token không cần token hợp lệ
            // Chỉ cần email từ body để query database
            if ($action === 'refresh_token') {
                // Lấy email từ params (đã được parse từ request body)
                $email = $params['email'] ?? null;
                
                if ($email) {
                    // Nếu có email trong body, cho phép pass qua
                    return [
                        'email' => $email,
                        'role' => $params['role'] ?? 'student'
                    ];
                }
                
                // Nếu không có email, thử lấy từ token (nếu vẫn còn)
                $token = null;
                if (isset($_COOKIE['access_token']) || isset($_COOKIE['auth_token'])) {
                    $token = $_COOKIE['access_token'] ?? $_COOKIE['auth_token'];
                } else {
                    $headers = getallheaders();
                    if(isset($headers["Authorization"])){
                        $authHeader = $headers["Authorization"];
                        if (strpos($authHeader, "Bearer ") === 0) {
                            $parts = explode(" ", $authHeader, 2);
                            if (count($parts) === 2) {
                                $token = $parts[1];
                            }
                        }
                    }
                }
                
                if ($token) {
                    $jwtHandler = new JwtHandler;
                    // Thử verify token (có thể hết hạn)
                    $result = $jwtHandler->verifyToken($token);
                    
                    if ($result && isset($result['data']['email'])) {
                        return $result['data'];
                    }
                    
                    // Nếu token hết hạn, decode mà không verify
                    $parts = explode(".", $token);
                    if (count($parts) === 3) {
                        $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
                        if (isset($payload['data']['email'])) {
                            return $payload['data'];
                        }
                    }
                }
                
                http_response_code(401);
                return ["error" => "Missing email or valid token"];
            }
            
            // Protected actions bình thường - cần token hợp lệ
            $token = null;
            
            // Ưu tiên lấy token từ cookie
            if (isset($_COOKIE['access_token']) || isset($_COOKIE['auth_token'])) {
                $token = $_COOKIE['access_token'] ?? $_COOKIE['auth_token'];
            } 
            // Nếu không có trong cookie, thử lấy từ Authorization header
            else {
                $headers = getallheaders();
                if(isset($headers["Authorization"])){
                    $authHeader = $headers["Authorization"];
                    if (strpos($authHeader, "Bearer ") === 0) {
                        $parts = explode(" ", $authHeader, 2);
                        if (count($parts) === 2) {
                            $token = $parts[1];
                        }
                    }
                }
            }
            
            // Nếu không tìm thấy token
            if (empty($token)) {
                http_response_code(401);
                return ["error" => "Missing access token. Please login."];
            }
            
            $jwtHandler = new JwtHandler;
            $result = $jwtHandler->verifyToken($token);
            
            // Nếu token không hợp lệ hoặc đã hết hạn
            if($result === null){
                http_response_code(401);
                return ["error" => "Invalid or expired access token"];
            }

            // Kiểm tra quyền admin cho các action đặc biệt
            if (($action === 'delete' || $action === 'AdminUpdate' || $action === 'add') && $result['data']['role'] !== 'admin') {
                http_response_code(403);
                return ['error' => 'Only admins can do this action'];
            }
            
            return $result['data'];
        }
        return null;
    }
}
?>