<?php
require_once __DIR__ . '/../Model/mSQL.php';
require_once __DIR__ . '/DataController.php';
require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';

class ApiController {
    private $dataController;
    private $authController;
    private $modelSQL;

    public function __construct() {
        $this->dataController = new DataController();
        $this->authController = new AuthController();
        $this->modelSQL = new ModelSQL();
    }

    public function handleRequest($action, $params) {
        error_log("Action: $action");
        error_log("Params: " . print_r($params, true));

        // Kiểm tra CSRF token cho POST
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($params['csrf_token'])) {
            http_response_code(403);
            return ['error' => 'Invalid CSRF token'];
        }

        // Xác thực qua middleware
        $middlewareResult = AuthMiddleware::verifyRequest($action);
        if (isset($middlewareResult['error'])) {
            return ['error' => $middlewareResult['error']];
        }

        $params = array_merge($params, $middlewareResult ?? []);

        // Xử lý action
        switch ($action) {
            case 'login':
                $table = $params['table'] ?? 'account';
                $google_id = $params['GoogleID'] ?? null;
                $email = $params['email'] ?? null;
                $full_name = $params['FullName'] ?? null;
                $role = $params['role'] ?? 'customer';
                $access_token = $params['access_token'] ?? null;
                $expires_at = $params['expires_at'] ?? null;

                if ($google_id && $email && $full_name && $access_token && $expires_at) {
                    $user = $this->authController->GetUserIdByGoogleId($google_id);
                    if (!$user) {
                        $data = [
                            'GoogleID' => $google_id,
                            'email' => $email,
                            'FullName' => $full_name,
                            'role' => $role
                        ];
                        $addUserResult = $this->dataController->addData($table, $data);
                        if (!$addUserResult) {
                            return ['error' => 'Thêm người dùng thất bại'];
                        }
                    }

                    $insertResult = $this->modelSQL->insert('user_tokens', [
                        'google_id' => $google_id,
                        'refresh_token' => $access_token,
                        'expires_at' => $expires_at
                    ]);
                    if (!$insertResult) {
                        return ['error' => 'Lưu access token thất bại'];
                    }

                    $token = $this->authController->LoginWithGoogle($google_id);
                    if (isset($token['error']) || !$token['token']) {
                        return ['error' => $token['error'] ?? 'Tạo token thất bại'];
                    }
                    return [
                        'status' => 'success',
                        'token' => $token['token'],
                        'message' => $user ? 'Đăng nhập thành công' : 'Thêm và đăng nhập thành công'
                    ];
                }
                return ['error' => 'Thiếu thông tin'];

            case 'get':
                $limit = $params['limit'] ?? '';
                $table = $params['table'] ?? 'account';
                $conditions = array_filter($params, fn($key) => !in_array($key, ['table', 'action', 'csrf_token']), ARRAY_FILTER_USE_KEY);
                $columns = $params['columns'] ?? ['*'];
                $orderBy = $params['orderBy'] ?? '';
                

                $data = $this->dataController->getData($table, $conditions, $columns, $orderBy, $limit);
                return $data ?: ['message' => 'Không có dữ liệu'];

            case 'add':
                $table = $params['table'] ?? 'account';
                $data = array_filter($params, fn($key) => !in_array($key, ['table', 'action', 'csrf_token']), ARRAY_FILTER_USE_KEY);
                $data['role'] = $data['role'] ?? 'customer';

                if (!empty($data)) {
                    $google_id = $data['GoogleID'] ?? null;
                    $user = $google_id ? $this->authController->GetUserIdByGoogleId($google_id) : null;
                    if (!$user) {
                        if ($this->dataController->addData($table, $data)) {
                            $token = $google_id ? $this->authController->LoginWithGoogle($google_id) : null;
                            return [
                                'status' => 'success',
                                'token' => $token['token'] ?? null,
                                'message' => 'Thêm thành công'
                            ];
                        }
                        return ['message' => 'Thêm thất bại'];
                    }
                    $token = $google_id ? $this->authController->LoginWithGoogle($google_id) : null;
                    return [
                        'status' => 'success',
                        'token' => $token['token'] ?? null,
                        'message' => 'Người dùng đã tồn tại'
                    ];
                }
                return ['message' => 'Thiếu thông tin'];
            case 'AdminUpdate':
                if($params['role'] !== 'admin'){
                    http_response_code(403);
                    return ['error' => 'Chỉ admin mới có quyền này'];
                }
                $table = $params['table'] ?? 'account';
                $data = array_filter($params, fn($key) => !in_array($key, ['table', 'action', 'csrf_token', 'GoogleID']), ARRAY_FILTER_USE_KEY);
                $conditions = ['GoogleID' => $params['GoogleID'] ?? null];

                if ($conditions['GoogleID'] && !empty($data)) {
                    if ($this->dataController->updateData($table, $data, $conditions)) {
                        return ['status' => 'success'];
                    }
                    return ['status' => 'error'];
                }
                return ['message' => 'Thiếu thông tin'];
            case 'update':
                if($params['role'] === 'customer' && $params['table'] === 'account'){
                    $table = $params['table'] ?? 'account';
                    $data = array_filter($params, fn($key) => !in_array($key, ['table', 'action', 'csrf_token', 'GoogleID']), ARRAY_FILTER_USE_KEY);
                    $conditions = ['GoogleID' => $params['GoogleID'] ?? null];

                    if ($conditions['GoogleID'] && !empty($data)) {
                        if ($this->dataController->updateData($table, $data, $conditions)) {
                            return ['status' => 'success'];
                        }
                        return ['status' => 'error'];
                    }
                    return ['message' => 'Thiếu thông tin'];
                }else{
                    http_response_code(403);
                    return ['error' => 'Chỉ khách hàng mới có quyền này'];
                }
            case 'delete':
                $table = $params['table'] ?? 'account';
                $conditions = array_filter($params, fn($key) => !in_array($key, ['table', 'action', 'csrf_token']), ARRAY_FILTER_USE_KEY);

                if (!empty($conditions)) {
                    if ($this->dataController->deleteData($table, $conditions)) {
                        return ['message' => 'Xóa thành công'];
                    }
                    return ['message' => 'Xóa thất bại'];
                }
                return ['message' => 'Thiếu điều kiện'];

            case 'refresh_token':
                $google_id = $params['GoogleID'] ?? null;
                if ($google_id) {
                    $data = $this->dataController->getData('user_tokens', ['google_id' => $google_id], ['refresh_token']);
                    if ($data) {
                        return ['refresh_token' => $data[0]['refresh_token']];
                    }
                    return ['error' => 'Token not found or expired'];
                }
                return ['error' => 'Missing GoogleID'];

            case 'logout':
                $google_id = $middlewareResult['GoogleID'] ?? null;
                if ($google_id) {
                    if ($this->dataController->deleteData('user_tokens', ['google_id' => $google_id])) {
                        return ['status' => 'success', 'message' => 'Đăng xuất thành công'];
                    }
                    return ['error' => 'Đăng xuất thất bại'];
                }
                return ['error' => 'Không tìm thấy GoogleID'];

            default:
                return ['error' => 'Hành động không hợp lệ'];
        }
    }
}

// require_once __DIR__ . '/../Model/mSQL.php';
// require_once __DIR__ . '/DataController.php';
// require_once __DIR__ . '/AuthController.php';
// require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
// class ApiController{
//     private $dataController;
//     private $authController;
//     private $modelSQL;

//     public function __construct(){
//         $this->dataController = new DataController();
//         $this->authController = new AuthController();
//         $this->modelSQL = new ModelSQL();
//     }

//     public function handleRequest($action, $params){
        
//         error_log("Action: $action");
//         error_log("Params: " . print_r($params, true));

//         if ($_SERVER['REQUEST_METHOD'] === 'POST') {
//             if (!isset($params['csrf_token']) ?? '') {
//                 http_response_code(403);
//                 return ['error' => 'Invalid CSRF token'];
//             }
//         }
//         $middlewareResult = AuthMiddleware::verifyRequest($action);
//         if (isset($middlewareResult['error'])) {
//             return ['error' => $middlewareResult['error']];
//             exit;
//         }

//         $params = array_merge($params, $middlewareResult ?? []);
//         switch($action){
            
//             case 'login':
//                 $google_id = $params['GoogleID'] ?? null;
//                 $email = $params['email'] ?? null;
//                 $full_name = $params['FullName'] ?? null;
//                 $role = $params['role'] ?? 'customer';
//                 //$refresh_token = $params['refresh_token'] ?? null;
//                 $access_token = $params['access_token'] ?? null;
//                 $expires_at = $params['expires_at'] ?? null;

//                 if ($google_id && $email && $full_name && $access_token && $expires_at) {
//                     // Kiểm tra và thêm người dùng vào account trước
//                     $user = $this->authController->GetUserIdByGoogleId($google_id);
//                     error_log("User found: " . ($user ? print_r($user, true) : 'Null'));

//                     if (!$user) {
//                         $addUserResult = $this->dataController->AddUser($google_id, $email, $full_name, $role);
//                         error_log("Add user result: " . ($addUserResult ? 'Success' : 'Failed'));
//                         if (!$addUserResult) {
//                             return ['error' => 'Thêm người dùng thất bại'];
//                         }
//                     }

//                     // Sau khi chắc chắn user tồn tại trong account, chèn vào user_tokens
//                     //$refresh_token = bin2hex(random_bytes(32));
//                     $insertResult = $this->modelSQL->Insert('user_tokens', [
//                         'google_id' => $google_id,
//                         'refresh_token' => $access_token,
//                         'expires_at' => $expires_at
//                     ]);
//                     error_log("Insert user_tokens result: " . ($insertResult ? 'Success' : 'Failed'));
//                     if (!$insertResult) {
//                         return ['error' => 'Lưu access token thất bại'];
//                     }

//                     $token = $this->authController->LoginWithGoogle($google_id);
//                     error_log("Token: " . ($token['token'] ?? 'Null'));
//                     if (isset($token['error'])) {
//                         return ['error' => $token['error']];
//                     }
//                     if (!$token['token']) {
//                         return ['error' => 'Tạo token thất bại'];
//                     }
//                     return [
//                         'status' => 'success',
//                         'token' => $token['token'],
//                         'message' => $user ? 'Người dùng đã tồn tại, đăng nhập thành công' : 'Thêm thành công'
//                     ];
//                 }
//                 return ['error' => 'Thiếu thông tin'];
//             case 'get':
//                 //$id = $params['id'] ?? null;

//                 $google_id = $params['GoogleID'] ?? null;
//                 $email = $params['email'] ?? null;
//                 $full_name = $params['FullName'] ?? null;
//                 $role = $params['role'] ?? null;
//                 $data = $this->dataController->GetUserData($google_id, $email, $full_name, $role);
//                 $result = [];
//                 if ($data) {
//                     while ($row = mysqli_fetch_assoc($data)) {
//                         $result[] = $row;
//                     }
//                     //header('Content-Type: application/json; charset=utf-8');
//                     return $result[0];
//                 }
//                 return ["message" => "Không có dữ liệu"];
//             case 'add':
//                 $google_id = $params['GoogleID'] ?? null;
//                 $email = $params['email'] ?? null;
//                 $full_name = $params['FullName'] ?? null;
//                 $role = $params['role'] ?? 'customer';

//                 if ($google_id && $email && $full_name) {
//                     $user = $this->authController->GetUserIdByGoogleId($google_id);
//                     if (!$user) {
//                         if ($this->dataController->AddUser($google_id, $email, $full_name, $role)) {
//                             $token = $this->authController->LoginWithGoogle($google_id);
//                             return [
//                                 'status' => 'success',
//                                 'token' => $token['token'],
//                                 'message' => 'Thêm thành công'
//                             ];
//                         }
//                         return ['message' => 'Thêm thất bại'];
//                     }
//                     $token = $this->authController->LoginWithGoogle($google_id);
//                     return [
//                         'status' => 'success',
//                         'token' => $token['token'],
//                         'message' => 'Người dùng đã tồn tại, đăng nhập thành công'
//                     ];
//                 }
//                 return ['message' => 'Thiếu thông tin người dùng'];

//             case 'update':
//                 $google_id = $params['GoogleID'] ?? null;
//                 $email = $params['email'] ?? null;
//                 $full_name = $params['FullName'] ?? null;
//                 $phone= $params['Phone'] ?? null;
//                 $address= $params['Address'] ?? null;
//                 $birthdate= $params['BirthDate'] ?? null;
//                 $identitynumber= $params['IdentityNumber'] ?? null;
//                 if ($this->dataController->UpdateUser($google_id, $email, $full_name, $phone, $address,$birthdate,$identitynumber)) {
//                     return ['status' => 'success'];
//                 }
                
//                 return ['status' => 'error'];

//             case 'delete':
//                 $id = $params['id'] ?? null;
//                 if ($this->dataController->DeleteUser($id)) {
//                     return ['message' => 'Xóa thành công'];
//                 }
//                 return ['message' => 'Xóa thất bại'];

//             case 'refresh_token':
//                 $exp = $params['exp'] ?? null;
//                 $google_id = $params['GoogleID'] ?? null;
//                 if ($google_id) {
//                     $p = new connect;
//                     $con = $p->OpenDB();
//                     $stmt = $con->prepare("SELECT refresh_token FROM user_tokens WHERE google_id = ?");
//                     $stmt->bind_param("s", $google_id);
//                     $stmt->execute();
//                     $result = $stmt->get_result();
//                     $token = $result->fetch_assoc();
//                     $p->closeDB();

//                     if ($token) {
//                         return ['refresh_token' => $token['refresh_token']];
//                     }
//                     return ['error' => 'Token not found or expired'];
//                 }
//                 return ['error' => 'Missing GoogleID'];
//             case 'logout':
//                 // Lấy GoogleID từ token (sau khi middleware xác thực)
//                 $userData = $middlewareResult; // Dữ liệu người dùng từ token
//                 $google_id = $userData['GoogleID'] ?? null;

//                 if (!$google_id) {
//                     return ['error' => 'Không tìm thấy GoogleID'];
//                 }

//                 // Xóa refresh_token từ bảng user_tokens
//                 $deleteResult = $this->modelSQL->Delete('user_tokens', "google_id = '$google_id'");
//                 error_log("Delete user_tokens result: " . ($deleteResult ? 'Success' : 'Failed'));

//                 if ($deleteResult) {
//                     return [
//                         'status' => 'success',
//                         'message' => 'Đăng xuất thành công'
//                     ];
//                 }
//                 return ['error' => 'Đăng xuất thất bại'];
//             default:
//                 return ['error' => 'Hành động không hợp lệ'];
//         }
//     }
// }
?>