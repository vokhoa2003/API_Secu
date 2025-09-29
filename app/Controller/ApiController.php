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

    private function checkCsrf($params) {
        $method = $_SERVER['REQUEST_METHOD'];
        if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
            if (empty($params['csrf_token']) || !isset($_COOKIE['csrf_token']) || $_COOKIE['csrf_token'] !== $params['csrf_token']) {
                http_response_code(403);
                return false;
            }
        }
        return true;
    }

    public function handleRequest($action, $params) {
        // error_log("Action: $action");
        // error_log("Params: " . print_r($params, true));

        // // Kiểm tra CSRF token cho các phương thức thay đổi dữ liệu
        // if (!$this->checkCsrf($params)) {
        //     return [
        //         'status' => 'error',
        //         'message' => 'Invalid CSRF token'
        //     ];
        // }

        // //Chỉ xác thực token với các action cần bảo vệ
        // $actionsRequireAuth = ['get', 'update', 'delete', 'logout', 'refresh_token'];
        // if (in_array($action, $actionsRequireAuth)) {
        //     $middlewareResult = AuthMiddleware::verifyRequest($action);
        //     if (isset($middlewareResult['error'])) {
        //         http_response_code(401);
        //         return [
        //             'status' => 'error',
        //             'message' => $middlewareResult['error']
        //         ];
        //     }
        //     // Luôn lấy GoogleID và role từ token đã xác thực
        //     $params['GoogleID'] = $middlewareResult['GoogleID'];
        //     $params['role'] = $middlewareResult['role'];
        // }
        switch ($action) {
            case 'login':
                $table = $params['table'] ?? 'account';
                $google_id = $params['GoogleID'] ?? null;
                $email = $params['email'] ?? null;
                $full_name = $params['FullName'] ?? null;
                $role = $params['role'] ?? 'student';
                $status = $params['status'] ?? 'Active';
                $access_token = $params['access_token'] ?? null;
                $expires_at = $params['expires_at'] ?? null;

                if ($google_id && $email && $full_name && $access_token && $expires_at) {
                    $user = $this->authController->GetUserIdByGoogleId($google_id);
                    if($user){
                        if(isset($user['role']) && $user['role'] === 'student'){
                            $addRelatedTable = $this -> dataController -> addData('student', [
                                'IdAccount' => $user['id'],
                                'Name' => $user['FullName']
                            ]);
                        }
                        if(isset($user['role']) && $user['role'] === 'teacher'){
                            $addRelatedTable = $this -> dataController -> addData('teacher', [
                                'IdAccount' => $user['id'],
                                'Name' => $user['FullName']
                            ]);
                        }
                        if(isset($user['role']) && $user['role'] === 'admin'){
                            $addRelatedTable = $this -> dataController -> addData('admin', [
                                'IdAccount' => $user['id'],
                                'Name' => $user['FullName']
                            ]);
                        }
                    }
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
                            return [
                                'status' => 'error',
                                'message' => 'Thêm người dùng thất bại'
                            ];
                        }
                    }

                    $insertResult = $this->modelSQL->insert('user_tokens', [
                        'google_id' => $google_id,
                        'refresh_token' => $access_token,
                        'expires_at' => $expires_at
                    ]);

                    if (!$insertResult) {
                        return [
                            'status' => 'error',
                            'message' => 'Lưu access token thất bại'
                        ];
                    }

                    $token = $this->authController->LoginWithGoogle($google_id);
                    if (isset($token['error']) || !$token['token']) {
                        return [
                            'status' => 'error',
                            'message' => $token['error'] ?? 'Tạo token thất bại'
                        ];
                    }
                    return [
                        'status' => 'success',
                        'token' => $token['token'],
                        'message' => $user ? 'Đăng nhập thành công' : 'Thêm và đăng nhập thành công'
                    ];
                }
                return [
                    'status' => 'error',
                    'message' => 'Thiếu thông tin'
                ];

            case 'app_login':
                $table = $params['table'] ?? 'account';
                $google_id = $params['GoogleID'] ?? null;
                $email = $params['email'] ?? null;
                $full_name = $params['FullName'] ?? null;
                $role = $params['role'] ?? 'student';
                $access_token = $params['access_token'] ?? null;
                $expires_at = $params['expires_at'] ?? null;

                if ($google_id && $email && $full_name && $access_token && $expires_at) {
                    //$existingUser = $this->dataController->getData($table, ['email' => $email], ['GoogleID', 'role', 'status']);
                    $existingUser = $this->authController->GetUserIdByGoogleId($google_id);
                    if($existingUser){
                        if(isset($existingUser['role']) && $existingUser['role'] === 'student'){
                            $addRelatedTable = $this -> dataController -> addData('student', [
                                'IdAccount' => $existingUser['id'],
                                'Name' => $existingUser['FullName']
                            ]);
                        }
                        if(isset($existingUser['role']) && $existingUser['role'] === 'teacher'){
                            $addRelatedTable = $this -> dataController -> addData('teacher', [
                                'IdAccount' => $existingUser['id'],
                                'Name' => $existingUser['FullName']
                            ]);
                        }
                        if(isset($existingUser['role']) && $existingUser['role'] === 'admin'){
                            $addRelatedTable = $this -> dataController -> addData('admin', [
                                'IdAccount' => $user['id'],
                                'Name' => $user['FullName']
                            ]);
                        }
                    }
                    if ($existingUser) {
                        $dbGoogleId = $existingUser['GoogleID'] ?? null;
                        if ($dbGoogleId === null || $dbGoogleId !== $google_id) {
                            $updateResult = $this->dataController->updateData($table, ['GoogleID' => $google_id], ['email' => $email]);
                            if (!$updateResult) {
                                return [
                                    'status' => 'error',
                                    'message' => 'Cập nhật GoogleID thất bại'
                                ];
                            }
                        }
                        $insertResult = $this->modelSQL->insert('user_tokens', [
                            'google_id' => $google_id,
                            'refresh_token' => $access_token,
                            'expires_at' => $expires_at
                        ]);
                        if (!$insertResult) {
                            return [
                                'status' => 'error',
                                'message' => 'Lưu access token thất bại'
                            ];
                        }
                        $token = $this->authController->LoginWithGoogle($google_id);
                        if (isset($token['error']) || !$token['token']) {
                            return [
                                'status' => 'error',
                                'message' => $token['error'] ?? 'Tạo token thất bại'
                            ];
                        }
                        return [
                            'status' => 'success',
                            'token' => $token['token'],
                            'message' => 'Đăng nhập thành công app',
                            'role' => $existingUser['role'],
                            'account_status' => $existingUser['Status']
                        ];
                    } else {
                        return [
                            'status' => 'error',
                            'message' => 'Tài khoản không tồn tại hoặc chưa được admin tạo'
                        ];
                    }
                }
                return [
                    'status' => 'error',
                    'message' => 'Thiếu thông tin'
                ];

            case 'get':
                
                $limit = $params['limit'] ?? '';
                $table = $params['table'] ?? 'account';
                $columns = $params['columns'] ?? ['*'];
                $orderBy = $params['orderBy'] ?? '';
                if ($table === 'account'){
                // Chỉ cho phép khách hàng xem dữ liệu của chính mình
                    if ($params['role'] === 'student') {
                        $conditions = ['GoogleID' => $params['GoogleID']];
                    } elseif ($params['role'] === 'admin') {
                        if ($params['scope'] === 'self') {
                            $conditions = ['GoogleID' => $params['GoogleID']];
                        }
                        elseif ($params['scope'] === 'all') {
                            $conditions = [];
                        }elseif (empty($params['scope'])) {
                            $conditions = array_filter($params, fn($key) => !in_array($key, ['table','action','csrf_token','role','GoogleID']), ARRAY_FILTER_USE_KEY);
                        }
                        else {
                            http_response_code(400);
                            return [
                                'status' => 'error',
                                'message' => 'Invalid scope parameter'
                            ];
                        }
                        // if (empty($conditions)) {
                        //     http_response_code(403);
                        //     return [
                        //         'status' => 'error',
                        //         'message' => 'Admin must specify query conditions'
                        //     ];
                        // }
                    } else {
                        http_response_code(403);
                        return [
                            'status' => 'error',
                            'message' => 'Permission denied'
                        ];
                    }
                } else {
                    $allowedFields = [
                        'questions' => ['id','TestNumber','ClassId','TeacherId','CreateDate','UpdateDate','PublishDate'],
                        'answers'   => ['id','QuestionId','IsCorrect'],
                        'exams'     => ['id','ExamName','TeacherId','CreateDate'],
                        // thêm các bảng khác vào đây
                    ];

                    $conditions = [];
                    if (isset($allowedFields[$table])) {
                        foreach ($allowedFields[$table] as $field) {
                            if (isset($params[$field])) {
                                $conditions[$field] = $params[$field];
                            }
                        }
                    } else {
                        // bảng chưa khai báo whitelist
                        http_response_code(400);
                        return [
                            'status'  => 'error',
                            'message' => "Table `$table` not allowed or not defined in whitelist"
                        ];
                    }
                }

                $data = $this->dataController->getData($table, $conditions, $columns, $orderBy, $limit);
                if(isset($data[0]['GoogleID'])){
                    foreach ($data as &$row) {
                        if (isset($row['GoogleID'])) {
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
                            return [
                                'status' => 'success',
                                'message' => 'Thêm thành công'
                            ];
                        }
                        return [
                            'status' => 'error',
                            'message' => 'Thêm thất bại'
                        ];
                    }
                    return [
                        'status' => 'error',
                        'message' => 'Người dùng đã tồn tại'
                    ];
                }
                return [
                    'status' => 'error',
                    'message' => 'Thiếu thông tin'
                ];

            case 'AdminUpdate':
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
                    'status' => 'error',
                    'message' => 'Thiếu thông tin'
                ];

            case 'update':
                if($params['role'] === 'customer' && $params['table'] === 'account'){
                    $table = $params['table'] ?? 'account';
                    $data = array_filter($params, fn($key) => !in_array($key, ['table', 'action', 'csrf_token', 'GoogleID']), ARRAY_FILTER_USE_KEY);
                    $conditions = ['GoogleID' => $params['GoogleID'] ?? null];

                    if ($conditions['GoogleID'] && !empty($data)) {
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
                        'status' => 'error',
                        'message' => 'Thiếu thông tin'
                    ];
                }else{
                    http_response_code(403);
                    return [
                        'status' => 'error',
                        'message' => 'Chỉ khách hàng mới có quyền này'
                    ];
                }

            case 'delete':
                $table = $params['table'] ?? 'account';
                $conditions = array_filter($params, fn($key) => !in_array($key, ['table', 'action', 'csrf_token']), ARRAY_FILTER_USE_KEY);

                if (!empty($conditions)) {
                    if ($this->dataController->deleteData($table, $conditions)) {
                        return [
                            'status' => 'success',
                            'message' => 'Xóa thành công'
                        ];
                    }
                    return [
                        'status' => 'error',
                        'message' => 'Xóa thất bại'
                    ];
                }
                return [
                    'status' => 'error',
                    'message' => 'Thiếu điều kiện'
                ];

            case 'refresh_token':
                $table = $params['table'] ?? 'user_tokens';
                $google_id = $params['GoogleID'] ?? null;
                if ($google_id) {
                    $data = $this->dataController->getData($table, ['google_id' => $google_id], ['refresh_token']);
                    if ($data) {
                        return [
                            'status' => 'success',
                            'refresh_token' => $data[0]['refresh_token']
                        ];
                    }
                    return [
                        'status' => 'error',
                        'message' => 'Token not found or expired'
                    ];
                }
                return [
                    'status' => 'error',
                    'message' => 'Missing GoogleID'
                ];

            case 'logout':
                $table = $params['table'] ?? 'user_tokens';
                $google_id = $middlewareResult['GoogleID'] ?? null;
                if ($google_id) {
                    if ($this->dataController->deleteData($table, ['google_id' => $google_id])) {
                        return [
                            'status' => 'success',
                            'message' => 'Đăng xuất thành công'
                        ];
                    }
                    return [
                        'status' => 'error',
                        'message' => 'Đăng xuất thất bại'
                    ];
                }
                return [
                    'status' => 'error',
                    'message' => 'Không tìm thấy GoogleID'
                ];

            case 'autoGet':
                $tables = $params['table'] ?? '';
                $columns = $params['columns'] ?? ['*'];
                $join = $params['join'] ?? [];
                $conditions = $params['conditions'] ?? [];

                $result = $this->modelSQL->autoQuery($tables, $columns, $join, $conditions);
                $data = [];
                if ($result instanceof mysqli_result) {
                    while ($row = $result->fetch_assoc()) {
                        $data[] = $row;
                    }
                } else {
                    $data = $result;
                }
                return [
                    'status' => 'success',
                    'data' => $data
                ];

            default:
                return [
                    'status' => 'error',
                    'message' => 'Hành động không hợp lệ'
                ];
        }
    }
}
?>