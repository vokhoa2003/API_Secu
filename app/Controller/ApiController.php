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

    // Thay thế hàm checkCsrf hiện tại bằng phiên bản nhận thêm $action
    private function checkCsrf($params, $action = null) {
        $method = $_SERVER['REQUEST_METHOD'];
        if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
            $tokenParam = $params['csrf_token'] ?? null;
            $tokenCookie = $_COOKIE['csrf_token'] ?? null;

            // Nếu action là app_login (desktop/mobile client) cho phép khi client gửi csrf_token trong body
            if ($action === 'app_login' && $tokenParam) {
                return true;
            }

            // 1) Nếu cả cookie và param tồn tại và khớp -> ok
            if ($tokenParam && $tokenCookie && hash_equals($tokenCookie, $tokenParam)) {
                return true;
            }

            // 2) Kiểm tra header X-CSRF-Token (case-insensitive)
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            $headerToken = null;
            foreach (['X-CSRF-Token','x-csrf-token','X-Csrf-Token'] as $h) {
                if (isset($headers[$h])) { $headerToken = $headers[$h]; break; }
            }
            if ($headerToken && $tokenParam && hash_equals($headerToken, $tokenParam)) {
                return true;
            }

            // 3) Nếu client gửi Cookie header trực tiếp (ví dụ Java client), parse và so sánh
            $cookieStr = $headers['Cookie'] ?? ($headers['cookie'] ?? null);
            if ($cookieStr) {
                $cookieParts = [];
                foreach (explode(';', $cookieStr) as $part) {
                    $kv = explode('=', trim($part), 2);
                    if (count($kv) === 2) {
                        $cookieParts[$kv[0]] = $kv[1];
                    }
                }
                if (isset($cookieParts['csrf_token']) && $tokenParam && hash_equals($cookieParts['csrf_token'], $tokenParam)) {
                    return true;
                }
            }

            // Nếu chưa có match, trả về lỗi
            http_response_code(403);
            return false;
        }
        return true;
    }

    public function handleRequest($action, $params) {
        error_log("Action: $action");
        error_log("Params: " . print_r($params, true));

        // Kiểm tra CSRF token (truyền action để special-case app_login)
        if (!$this->checkCsrf($params, $action)) {
            return [
                'status' => 'error',
                'message' => 'Invalid CSRF token'
            ];
        }

        //Chỉ xác thực token với các action cần bảo vệ
        $actionsRequireAuth = ['get', 'update', 'delete', 'logout', 'refresh_token'];
        if (in_array($action, $actionsRequireAuth)) {
            $middlewareResult = AuthMiddleware::verifyRequest($action);
            if (isset($middlewareResult['error'])) {
                http_response_code(401);
                return [
                    'status' => 'error',
                    'message' => $middlewareResult['error']
                ];
            }
            // Luôn lấy GoogleID và role từ token đã xác thực
            $params['email'] = $middlewareResult['email'];
            $params['role'] = $middlewareResult['role'];
        }
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

                if ($email && $full_name && $access_token && $expires_at) {
                    // ưu tiên tìm bằng GoogleID nếu có
                    $user = null;
                    if ($google_id) {
                        $user = $this->authController->GetUserIdByGoogleId($google_id);
                    }

                    // Nếu chưa tìm thấy bằng GoogleID thì thử tìm bằng email
                    if (!$user && $email) {
                        $user = $this->authController->GetUserByEmail($email);
                    }

                    // Nếu vẫn không có user -> CHẶN (không tự tạo)
                    if (!$user) {
                        return [
                            'status' => 'error',
                            'message' => 'Tài khoản không tồn tại. Vui lòng liên hệ quản trị để đăng ký.'
                        ];
                    }

                    // Nếu tìm được user bằng email nhưng GoogleID chưa lưu và params có GoogleID -> cập nhật
                    if (!empty($google_id) && (empty($user['GoogleID']) || $user['GoogleID'] !== $google_id)) {
                        $updateCond = [];
                        // nếu có id trong record thì dùng id để update, ngược lại dùng email
                        if (!empty($user['id'])) {
                            $updateCond = ['id' => $user['id']];
                        } else {
                            $updateCond = ['email' => $email];
                        }
                        $this->dataController->updateData('account', ['GoogleID' => $google_id], $updateCond);
                        // tải lại user
                        $user = $this->authController->GetUserIdByGoogleId($google_id) ?: ($this->authController->GetUserByEmail($email) ?? $user);
                    }

                    // Đảm bảo bảng chi tiết (student/teacher/admin) có bản ghi liên quan
                    $userRole = $user['role'] ?? $role;
                    if ($userRole === 'student') {
                        $exists = $this->dataController->getData('student', ['IdAccount' => $user['id']]);
                        if (!$exists) {
                            $this->dataController->addData('student', [
                                'IdAccount' => $user['id'],
                                'Name' => $user['FullName'] ?? $full_name
                            ]);
                        }
                    } elseif ($userRole === 'teacher') {
                        $exists = $this->dataController->getData('teacher', ['IdAccount' => $user['id']]);
                        if (!$exists) {
                            $this->dataController->addData('teacher', [
                                'IdAccount' => $user['id'],
                                'Name' => $user['FullName'] ?? $full_name
                            ]);
                        }
                    } elseif ($userRole === 'admin') {
                        $exists = $this->dataController->getData('admin', ['IdAccount' => $user['id']]);
                        if (!$exists) {
                            $this->dataController->addData('admin', [
                                'IdAccount' => $user['id'],
                                'Name' => $user['FullName'] ?? $full_name
                            ]);
                        }
                    }

                    // Lưu token (refresh_token)
                    $insertResult = $this->modelSQL->insert('user_tokens', [
                        'google_id' => $google_id ?? ($user['GoogleID'] ?? null),
                        'refresh_token' => $access_token,
                        'expires_at' => $expires_at
                    ]);

                    if (!$insertResult) {
                        return [
                            'status' => 'error',
                            'message' => 'Lưu access token thất bại'
                        ];
                    }

                    $token = $this->authController->LoginWithGoogle($google_id ?? ($user['GoogleID'] ?? null));
                    if (isset($token['error']) || !$token['token']) {
                        return [
                            'status' => 'error',
                            'message' => $token['error'] ?? 'Tạo token thất bại'
                        ];
                    }
                    return [
                        'status' => 'success',
                        'token' => $token['token'],
                        'message' => 'Đăng nhập thành công'
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

                if ($email && $full_name && $access_token && $expires_at) {
                    // Tìm user theo GoogleID nếu có
                    $existingUser = null;
                    if ($google_id) {
                        $existingUser = $this->authController->GetUserIdByGoogleId($google_id);
                    }

                    // Nếu không tìm thấy bằng GoogleID thì tìm bằng email
                    if (!$existingUser && $email) {
                        $existingUser = $this->authController->GetUserByEmail($email);
                    }

                    // Nếu không tồn tại -> CHẶN (không tự tạo)
                    if (!$existingUser) {
                        return [
                            'status' => 'error',
                            'message' => 'Tài khoản không tồn tại. Vui lòng đăng ký trước.'
                        ];
                    }

                    // Nếu tồn tại theo email nhưng GoogleID trong DB khác hoặc rỗng và params cung cấp GoogleID -> cập nhật
                    if (!empty($google_id) && (empty($existingUser['GoogleID']) || $existingUser['GoogleID'] !== $google_id)) {
                        $updateCond = !empty($existingUser['id']) ? ['id' => $existingUser['id']] : ['email' => $email];
                        $this->dataController->updateData('account', ['GoogleID' => $google_id], $updateCond);
                        // reload
                        $existingUser = $this->authController->GetUserIdByGoogleId($google_id) ?: ($this->authController->GetUserByEmail($email) ?? $existingUser);
                    }

                    // Tạo bản ghi liên quan nếu cần
                    if(isset($existingUser['role']) && $existingUser['role'] === 'student'){
                        $exists = $this->dataController->getData('student', ['IdAccount' => $existingUser['id']]);
                        if (!$exists) {
                            $this->dataController->addData('student', [
                                'IdAccount' => $existingUser['id'],
                                'Name' => $existingUser['FullName'] ?? $full_name
                            ]);
                        }
                    }
                    if(isset($existingUser['role']) && $existingUser['role'] === 'teacher'){
                        $exists = $this->dataController->getData('teacher', ['IdAccount' => $existingUser['id']]);
                        if (!$exists) {
                            $this->dataController->addData('teacher', [
                                'IdAccount' => $existingUser['id'],
                                'Name' => $existingUser['FullName'] ?? $full_name
                            ]);
                        }
                    }
                    if(isset($existingUser['role']) && $existingUser['role'] === 'admin'){
                        $exists = $this->dataController->getData('admin', ['IdAccount' => $existingUser['id']]);
                        if (!$exists) {
                            $this->dataController->addData('admin', [
                                'IdAccount' => $existingUser['id'],
                                //'Name' => $existingUser['FullName']
                            ]);
                        }
                    }

                    // Cập nhật GoogleID nếu cần (đã xử lý phía trên)
                    $insertResult = $this->modelSQL->insert('user_tokens', [
                        'google_id' => $google_id ?? ($existingUser['GoogleID'] ?? null),
                        'refresh_token' => $access_token,
                        'expires_at' => $expires_at
                    ]);
                    if (!$insertResult) {
                        return [
                            'status' => 'error',
                            'message' => 'Lưu access token thất bại'
                        ];
                    }
                    $token = $this->authController->LoginWithGoogle($google_id ?? ($existingUser['GoogleID'] ?? null));
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
                        'account_status' => $existingUser['Status'] ?? null
                    ];
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
                        $conditions = ['email' => $params['email']];
                    } elseif ($params['role'] === 'admin') {
                        if ($params['scope'] === 'self') {
                            $conditions = ['email' => $params['email']];
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
                    }
                    else if ($params['role'] === 'teacher'){
                        $conditions = ['email' => $params['email']];
                    }   
                     else {
                        http_response_code(403);
                        return [
                            'status' => 'error',
                            'message' => 'Permission denied'
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
                if ($table === 'classes'){
                    $conditions = ['Id' => $params['Id'] ?? null];
                } else{
                    $conditions = array_filter($params, fn($key) => !in_array($key, ['table', 'action', 'csrf_token']), ARRAY_FILTER_USE_KEY);
                }
                if (!empty($conditions)) {
                    if ($this->dataController->deleteData($table, $conditions)) {
                        return [
                            'status' => 'success',
                            'message' => 'Xóa thành công'
                        ];
                    }
                    return [
                        'status' => 'error',
                        'message' => 'Xóa thất bại',
                        'conditions' => $conditions
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
                $method = $params['method'] ?? '';
                $tables = $params['table'] ?? '';
                $columns = $params['columns'] ?? ['*'];
                $join = $params['join'] ?? [];
                $conditions = $params['conditions'] ?? [];
                $groupBy = $params['groupBy'] ?? [];

                $result = $this->modelSQL->autoQuery($tables, $columns, $join, $conditions, $groupBy);
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
            case 'autoUpdate':
                $table = $params['table'] ?? '';
                $data = $params['data'] ?? [];
                $method = $params['method'] ?? 'UPSERT';

                $result = $this->modelSQL->autoUpdate($table, $data, $method);
                return [
                    'status' => $result['status'],
                    'message' => $result['message']
                ];
            case 'multiInsert':
                $operations = $params['operations'] ?? [];
                // debug log
                file_put_contents(__DIR__.'/../../multi_insert_debug.log', date('c')." multiInsert payload: ".json_encode($operations)."\n", FILE_APPEND);
                $res = $this->modelSQL->multiInsert($operations);
                header('Content-Type: application/json');
                echo json_encode($res);
                return;
            default:
                return [
                    'status' => 'error',
                    'message' => 'Hành động không hợp lệ'
                ];
        }
    }
}
?>