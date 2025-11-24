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

            // lấy header token (case-insensitive)
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            $headerToken = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? $headers['X-Csrf-Token'] ?? null;

            // cookie token (của trình duyệt)
            $cookieToken = $_COOKIE['csrf_token'] ?? null;

            // server-side session token (nếu bạn lưu)
            if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
            $sessionToken = $_SESSION['csrf_token'] ?? null;

            // Special-case: app_login (mobile/desktop) cho phép tokenParam gửi trong body
            if ($action === 'app_login' && $tokenParam) {
                return true;
            }

            // 1) Accept headerToken matching cookie (double-submit) OR session token
            if ($headerToken) {
                if ($cookieToken && hash_equals($cookieToken, $headerToken)) return true;
                if ($sessionToken && hash_equals($sessionToken, $headerToken)) return true;
            }

            // 2) Fallback: tokenParam matching cookie or session (for clients sending in body)
            if ($tokenParam) {
                if ($cookieToken && hash_equals($cookieToken, $tokenParam)) return true;
                if ($sessionToken && hash_equals($sessionToken, $tokenParam)) return true;
            }

            // ✅ THÊM LOG ĐỂ BIẾT TẠI SAO FAIL
    error_log("CSRF CHECK FAILED!");
    error_log("headerToken: " . ($headerToken ?? 'NULL'));
    error_log("cookieToken: " . ($cookieToken ?? 'NULL'));
    error_log("tokenParam: " . ($tokenParam ?? 'NULL'));

            // 3) For some non-browser clients who send raw Cookie header in headers, parse and compare
            $cookieStr = $headers['Cookie'] ?? ($headers['cookie'] ?? null);
            if ($cookieStr && $tokenParam) {
                $cookieParts = [];
                foreach (explode(';', $cookieStr) as $part) {
                    $kv = explode('=', trim($part), 2);
                    if (count($kv) === 2) $cookieParts[$kv[0]] = $kv[1];
                }
                if (isset($cookieParts['csrf_token']) && hash_equals($cookieParts['csrf_token'], $tokenParam)) return true;
            }

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
        $actionsRequireAuth = ['get', 'update', 'delete', 'logout', 'refresh_token', 'autoGet', 'autoUpdate', 'AdminUpdate', 'muitiInsert'];
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
                            // DEBUG - trả về luôn để xem
                            // return [
                            //     'debug_params' => $params,
                            //     'debug_conditions' => $conditions
                            // ];
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
                                'data' => $data,
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
                
                // Lấy dữ liệu cần cập nhật (loại bỏ các key không phải cột)
                $data = array_filter($params, fn($key) => !in_array($key, ['table', 'action', 'csrf_token', 'GoogleID']), ARRAY_FILTER_USE_KEY);

                // Xây dựng điều kiện tìm bản ghi
                $conditions = [];

                // 1. Ưu tiên dùng Id (cho classes, teacher, student)
                if (!empty($params['Id'])) {
                    $conditions['Id'] = $params['Id'];
                }
                // 2. Nếu không có Id và là bảng account → dùng email
                elseif ($table === 'account' && !empty($params['email'])) {
                    $conditions['email'] = $params['email'];
                }
                // 3. Trường hợp lỗi
                else {
                    return [
                        'status' => 'error',
                        'message' => 'Thiếu Id (cho lớp/GV/HS) hoặc email (cho tài khoản)'
                    ];
                }

                // Kiểm tra có dữ liệu và điều kiện không
                if (empty($data)) {
                    return ['status' => 'error', 'message' => 'Không có dữ liệu để cập nhật'];
                }
                if (empty($conditions)) {
                    return ['status' => 'error', 'message' => 'Thiếu điều kiện tìm'];
                }

                // Gọi update
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
            case 'update':
                if(($params['role'] === 'student' || $params['role'] === 'teacher') && $params['table'] === 'account'){
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
    if ($table === 'classes' || $table === 'teacher' || $table === 'student'){
        $conditions = ['Id' => $params['Id'] ?? null];
    } else if($table === 'account'){
        // Check cả 'id' và 'Id'
        $id = $params['id'] ?? $params['Id'] ?? null;
        if ($id) {
            $conditions = ['id' => $id];
        } elseif (!empty($params['email'])) {
            $conditions = ['email' => $params['email']];
        } else {
            $conditions = [];
        }
    } else {
        $conditions = array_filter($params, fn($key) => !in_array($key, ['table', 'action', 'csrf_token', 'email', 'roles']), ARRAY_FILTER_USE_KEY);
    }
    
    if (!empty($conditions) && !in_array(null, $conditions, true)) {
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
    $table   = $params['table'] ?? '';
    $columns = $params['columns'] ?? ['*'];
    $join    = $params['join'] ?? [];

    // Xử lý where + whereIn
    $where = [];
    if (!empty($params['where']) && is_array($params['where']) && !isset($params['where'][0])) {
        $where = $params['where'];
    }
    if (!empty($params['whereIn'])) {
        foreach ($params['whereIn'] as $field => $values) {
            if (is_array($values) && !empty($values)) {
                $where[$field] = $values; // autoQuery sẽ tự hiểu là IN
            }
        }
    }

    // Xử lý orderBy
    $orderBy = '';
    if (!empty($params['orderBy']) && is_array($params['orderBy'])) {
        $parts = [];
        foreach ($params['orderBy'] as $col => $dir) {
            $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
            $parts[] = "`$col` $dir";
        }
        $orderBy = implode(', ', $parts);
    }

    // ĐÚNG THỨ TỰ THAM SỐ – ĐÂY LÀ CHÌA KHÓA!!!
    $result = $this->modelSQL->autoQuery(
        $table,
        $columns,
        $join,
        $where,
        $params['groupBy'] ?? '',   // groupBy
        $orderBy                    // ← THÊM DÒNG NÀY!!!
    );

    $data = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $result->free();
    }

    return [
        'status' => 'success',
        'data'   => $data,
        'count'  => count($data)
    ];
            case 'multiInsert':
                $operations = $params['operations'] ?? [];
                // debug log
                file_put_contents(__DIR__.'/../../multi_insert_debug.log', date('c')." multiInsert payload: "
                .json_encode($operations)."\n", FILE_APPEND);
                $res = $this->modelSQL->multiInsert($operations);
                header('Content-Type: application/json');
                echo json_encode($res);
                return;
    //         case 'ping':
    // return [
    //     'status' => 'success',
    //     'message' => 'Pong! API is alive.',
    //     'timestamp' => date('c'),
    //     'server' => $_SERVER['SERVER_NAME']
    // ];
            default:
                return [
                    'status' => 'error',
                    'message' => 'Hành động không hợp lệ'
                ];
        }
    }
}
?>