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

        //Kiểm tra CSRF token cho POST
        if (($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'GET' ) && empty($params['csrf_token']) ) {
        http_response_code(403);
        return ['error' => 'Invalid CSRF token'];
        exit;
        }
        // Xác thực qua middleware
        $middlewareResult = AuthMiddleware::verifyRequest($action);
        if (isset($middlewareResult['error'])) {
            return ['error' => $middlewareResult['error']];
            exit;
        }
        if (isset($middlewareResult['role']) && $middlewareResult['role'] === 'customer') {
            $params['GoogleID'] = $middlewareResult['GoogleID']; // hạn chế chỉ truy xuất của chính họ

        }      
        // Xử lý action
        switch ($action) {
            case 'login':
                $table = $params['table'] ?? 'account';
                $google_id = $params['GoogleID'] ?? null;
                $email = $params['email'] ?? null;
                $full_name = $params['FullName'] ?? null;
                $role = $params['role'] ?? 'customer';
                $status = $params['status'] ?? 'Active';
                $access_token = $params['access_token'] ?? null;
                $expires_at = $params['expires_at'] ?? null;

                if ($google_id && $email && $full_name && $access_token && $expires_at) {
                    $user = $this->authController->GetUserIdByGoogleId($google_id);
                    if (!$user) {
                        $data = [
                            'GoogleID' => $google_id,
                            'email' => $email,
                            'FullName' => $full_name,
                            'role' => $role,
                            'status' => $status
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
            case 'app_login':
                $table = $params['table'] ?? 'account';
                $google_id = $params['GoogleID'] ?? null;
                $email = $params['email'] ?? null;
                $full_name = $params['FullName'] ?? null;
                $access_token = $params['access_token'] ?? null;
                $expires_at = $params['expires_at'] ?? null;

                if ($google_id && $email && $full_name && $access_token && $expires_at) {
                    // Tìm user dựa trên email trước
                    $existingUser = $this->dataController->getData($table, ['email' => $email], ['GoogleID', 'role', 'status']);
                    
                    if ($existingUser) {
                        $dbGoogleId = $existingUser[0]['GoogleID'] ?? null;
                        
                        if ($dbGoogleId === null || $dbGoogleId !== $google_id) {
                            // Cập nhật GoogleID trong database nếu nó trống hoặc không khớp
                            $updateResult = $this->dataController->updateData($table, ['GoogleID' => $google_id], ['email' => $email]);
                            if (!$updateResult) {
                                return ['error' => 'Cập nhật GoogleID thất bại'];
                            }
                        }

                        // Tiếp tục đăng nhập
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
                            'message' => 'Đăng nhập thành công',
                            'role' => $existingUser[0]['role'],
                            'status' => $existingUser[0]['status']
                        ];
                    } else {
                        // User không tồn tại, từ chối tạo mới
                        return ['error' => 'Tài khoản không tồn tại hoặc chưa được admin tạo'];
                    }
                }
                return ['error' => 'Thiếu thông tin'];

            case 'get':
                $limit = $params['limit'] ?? '';
                $table = $params['table'] ?? 'account';
                $conditions = array_filter($params, fn($key) => !in_array($key, ['table', 'action', 'csrf_token']), ARRAY_FILTER_USE_KEY);
                $columns = $params['columns'] ?? ['*'];
                $orderBy = $params['orderBy'] ?? '';
                

                $data = $this->dataController->getData($table, $conditions, $columns, $orderBy, $limit);
                if(isset($data[0]['GoogleID'])){
                    foreach ($data as &$row) {
                        if (isset($row['GoogleID'])) {
                            unset($row['GoogleID']);
                            if (isset($row['IdentityNumber'])) {
                                $row['IdentityNumber'] = hash('sha256', $row['IdentityNumber']);
                            } else {
                                $row['IdentityNumber'] = null;
                            }
                        }
                    }
                }
                return $data ?: [
                    'status' => 'error',
                    'message' => 'Không có dữ liệu'
                ];

            case 'add':
                $table = $params['table'] ?? 'account';
                $data = array_filter($params, fn($key) => !in_array($key, ['table', 'action', 'csrf_token']), ARRAY_FILTER_USE_KEY);
                $data['role'] = $data['role'] ?? 'customer';
                if (!empty($data)) {
                    if(isset($data['email'])){
                        if($this->dataController->getData($table, ['email' => $data['email']])){
                            return [
                                'status' => 'error',
                                'message' => 'Người dùng đã tồn tại'
                            ];
                        }
                    }
                    $google_id = $data['GoogleID'] ?? null;
                    $user = $google_id ? $this->authController->GetUserIdByGoogleId($google_id) : null;
                    if (!$user) {
                        if ($this->dataController->addData($table, $data)) {
                            //$token = $google_id ? $this->authController->LoginWithGoogle($google_id) : null;
                            return [
                                'status' => 'success',
                                //'token' => $token['token'] ?? null,
                                'message' => 'Thêm thành công'
                            ];
                        }
                        return [
                            'status' => 'error',
                            'message' => 'Thêm thất bại'
                        ];
                    }
                    //$token = $google_id ? $this->authController->LoginWithGoogle($google_id) : null;
                    return [
                        'status' => 'error',
                        //'token' => $token['token'] ?? null,
                        'message' => 'Người dùng đã tồn tại'
                    ];
                }
                return ['message' => 'Thiếu thông tin'];
            case 'AdminUpdate':

                // if($params['role'] !== 'admin'){
                //     http_response_code(403);
                //     return [
                //         'message' => 'Chỉ admin mới có quyền này',
                //         'status' => 'error'
                //     ];
                // }
                $table = $params['table'] ?? 'account';
                $data = array_filter($params, fn($key) => !in_array($key, ['table', 'action', 'csrf_token', 'GoogleID']), ARRAY_FILTER_USE_KEY);
                $conditions = ['email' => $params['email'] ?? null];

                if ($conditions['email'] && !empty($data)) {
                    if ($this->dataController->updateData($table, $data, $conditions)) {
                        return [
                            'status' => 'success',
                            'message' => 'Cập nhật thành công'
                        ];
                    }
                    return [
                        'status' => 'error',
                        'message' => 'Cập nhật thất bại'
                    ];
                }
                return [
                    'message' => 'Thiếu thông tin',
                    'status' => 'error'
                ];
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
?>