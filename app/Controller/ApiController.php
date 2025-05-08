<?php
require_once __DIR__ . '/../Model/mSQL.php';
require_once __DIR__ . '/DataController.php';
require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
class ApiController{
    private $dataController;
    private $authController;
    private $modelSQL;

    public function __construct(){
        $this->dataController = new DataController();
        $this->authController = new AuthController();
        $this->modelSQL = new ModelSQL();
    }

    public function handleRequest($action, $params){
        
        error_log("Action: $action");
        error_log("Params: " . print_r($params, true));

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($params['csrf_token']) || $params['csrf_token'] !== ($_COOKIE['csrf_token'] ?? '')) {
                http_response_code(403);
                return ['error' => 'Invalid CSRF token'];
            }
        }
        $middlewareResult = AuthMiddleware::verifyRequest($action);
        if (isset($middlewareResult['error'])) {
            return ['error' => $middlewareResult['error']];
            exit;
        }
        switch($action){
            
            case 'login':
                case 'login':
                    $google_id = $params['GoogleID'] ?? null;
                    $email = $params['email'] ?? null;
                    $full_name = $params['FullName'] ?? null;
                    $role = $params['role'] ?? 'customer';
                    $refresh_token = $params['refresh_token'] ?? null;
                    $expires_at = $params['expires_at'] ?? null;

                    if ($google_id && $email && $full_name && $refresh_token && $expires_at) {
                        // Kiểm tra và thêm người dùng vào account trước
                        $user = $this->authController->GetUserIdByGoogleId($google_id);
                        error_log("User found: " . ($user ? print_r($user, true) : 'Null'));

                        if (!$user) {
                            $addUserResult = $this->dataController->AddUser($google_id, $email, $full_name, $role);
                            error_log("Add user result: " . ($addUserResult ? 'Success' : 'Failed'));
                            if (!$addUserResult) {
                                return ['error' => 'Thêm người dùng thất bại'];
                            }
                        }

                        // Sau khi chắc chắn user tồn tại trong account, chèn vào user_tokens
                        $refresh_token = bin2hex(random_bytes(32));
                        $insertResult = $this->modelSQL->Insert('user_tokens', [
                            'google_id' => $google_id,
                            'refresh_token' => $refresh_token,
                            'expires_at' => date('Y-m-d H:i:s', $expires_at + 7 * 24 * 3600)
                        ]);
                        error_log("Insert user_tokens result: " . ($insertResult ? 'Success' : 'Failed'));
                        if (!$insertResult) {
                            return ['error' => 'Lưu access token thất bại'];
                        }

                        $token = $this->authController->LoginWithGoogle($google_id);
                        error_log("Token: " . ($token['token'] ?? 'Null'));
                        if (isset($token['error'])) {
                            return ['error' => $token['error']];
                        }
                        if (!$token['token']) {
                            return ['error' => 'Tạo token thất bại'];
                        }
                        return [
                            'status' => 'success',
                            'token' => $token['token'],
                            'message' => $user ? 'Người dùng đã tồn tại, đăng nhập thành công' : 'Thêm thành công'
                        ];
                    }
                    return ['error' => 'Thiếu thông tin'];
            case 'get':
                //$id = $params['id'] ?? null;

                $google_id = $params['GoogleID'] ?? null;
                $email = $params['email'] ?? null;
                $full_name = $params['FullName'] ?? null;
                $role = $params['role'] ?? null;
                $data = $this->dataController->GetUserData($google_id, $email, $full_name, $role);
                $result = [];
                if ($data) {
                    while ($row = mysqli_fetch_assoc($data)) {
                        $result[] = $row;
                    }
                    //header('Content-Type: application/json; charset=utf-8');
                    return $result[0];
                }
                return ["message" => "Không có dữ liệu"];
            case 'add':
                $google_id = $params['GoogleID'] ?? null;
                $email = $params['email'] ?? null;
                $full_name = $params['FullName'] ?? null;
                $role = $params['role'] ?? 'customer';

                if ($google_id && $email && $full_name) {
                    $user = $this->authController->GetUserIdByGoogleId($google_id);
                    if (!$user) {
                        if ($this->dataController->AddUser($google_id, $email, $full_name, $role)) {
                            $token = $this->authController->LoginWithGoogle($google_id);
                            return [
                                'status' => 'success',
                                'token' => $token['token'],
                                'message' => 'Thêm thành công'
                            ];
                        }
                        return ['message' => 'Thêm thất bại'];
                    }
                    $token = $this->authController->LoginWithGoogle($google_id);
                    return [
                        'status' => 'success',
                        'token' => $token['token'],
                        'message' => 'Người dùng đã tồn tại, đăng nhập thành công'
                    ];
                }
                return ['message' => 'Thiếu thông tin người dùng'];

            case 'update':
                $id = $params['id'] ?? null;
                $google_id = $params['GoogleID'] ?? null;
                $email = $params['email'] ?? null;
                $full_name = $params['FullName'] ?? null;
                if ($this->dataController->UpdateUser($id, $google_id, $email, $full_name)) {
                    return ['message' => 'Cập nhật thành công'];
                }
                return ['message' => 'Cập nhật thất bại'];

            case 'delete':
                $id = $params['id'] ?? null;
                if ($this->dataController->DeleteUser($id)) {
                    return ['message' => 'Xóa thành công'];
                }
                return ['message' => 'Xóa thất bại'];

            case 'refresh_token':
                $exp = $params['exp'] ?? null;
                $google_id = $params['GoogleID'] ?? null;
                if ($google_id) {
                    $p = new connect;
                    $con = $p->OpenDB();
                    $stmt = $con->prepare("SELECT refresh_token FROM user_tokens WHERE google_id = ?");
                    $stmt->bind_param("s", $google_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $token = $result->fetch_assoc();
                    $p->closeDB();
                    
                    if ($token) {
                        return ['refresh_token' => $token['refresh_token']];
                    }
                    return ['error' => 'Token not found or expired'];
                }
                return ['error' => 'Missing GoogleID'];
            case 'logout':
                // Lấy GoogleID từ token (sau khi middleware xác thực)
                $userData = $middlewareResult; // Dữ liệu người dùng từ token
                $google_id = $userData['GoogleID'] ?? null;

                if (!$google_id) {
                    return ['error' => 'Không tìm thấy GoogleID'];
                }

                // Xóa refresh_token từ bảng user_tokens
                $deleteResult = $this->modelSQL->Delete('user_tokens', "google_id = '$google_id'");
                error_log("Delete user_tokens result: " . ($deleteResult ? 'Success' : 'Failed'));

                if ($deleteResult) {
                    return [
                        'status' => 'success',
                        'message' => 'Đăng xuất thành công'
                    ];
                }
                return ['error' => 'Đăng xuất thất bại'];
            default:
                return ['error' => 'Hành động không hợp lệ'];
        }
    }
}
?>