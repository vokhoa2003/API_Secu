<?php

require_once __DIR__ . '/../Model/mSQL.php';
require_once __DIR__ . '/DataController.php';
require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../Middleware/RateLimiter.php';
require_once __DIR__ . '/../../JwtHandler.php';

class ApiController
{
    private $dataController;
    private $authController;
    private $modelSQL;
    private $rateLimiter;
    private $jwtHandler;

    public function __construct()
    {
        $this->dataController = new DataController();
        $this->authController = new AuthController();
        $this->modelSQL = new ModelSQL();
        $this->rateLimiter = new RateLimiter();
        $this->jwtHandler = new JwtHandler();
    }

    // Thay thế hàm checkCsrf hiện tại bằng phiên bản nhận thêm $action
    private function checkCsrf($params, $action = null)
    {
        $method = $_SERVER['REQUEST_METHOD'];
        if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
            $tokenParam = $params['csrf_token'] ?? null;

            // lấy header token (case-insensitive)
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            $headerToken = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? $headers['X-Csrf-Token'] ?? null;

            // cookie token (của trình duyệt)
            $cookieToken = $_COOKIE['csrf_token'] ?? null;

            // server-side session token 
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

    private function verifyGoogleToken($accessToken)
    {
        $url = 'https://oauth2.googleapis.com/tokeninfo?access_token=' . urlencode($accessToken);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return false;
        }

        $tokenInfo = json_decode($response, true);

        // Kiểm tra token còn hạn và thuộc về app 
        if (
            !isset($tokenInfo['email']) ||
            !isset($tokenInfo['exp']) ||
            $tokenInfo['exp'] < time()
        ) {
            return false;
        }

        return $tokenInfo;
    }

    public function handleRequest($action, $params)
    {
        error_log("Action: $action");
        error_log("Params: " . print_r($params, true));

        // ==========================================
        // RATE LIMIT CHO LOGIN - TRƯỚC KHI CHECK CSRF
        // ==========================================
        if ($action === 'app_login' || $action === 'login') {
            $ip = $_SERVER['REMOTE_ADDR'];

            // Max 10 login attempts trong 5 phút
            if (!$this->rateLimiter->check('login:' . $ip, 2, 300)) {
                http_response_code(429);

                // KHÔNG redirect, chỉ trả JSON
                // callback.php sẽ tự xử lý redirect
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Quá nhiều lần thử đăng nhập. Vui lòng thử lại sau 5 phút.',
                    'error_code' => 'RATE_LIMIT_EXCEEDED',
                    'retry_after' => 300
                ], JSON_UNESCAPED_UNICODE);
                exit; //Quan trọng: exit ngay để không chạy code phía dưới
            }
        }
        //Kiểm tra CSRF token (truyền action để special-case app_login)
        if (!$this->checkCsrf($params, $action)) {
            return [
                'status' => 'error',
                'message' => 'Invalid CSRF token'
            ];
        }

        //Chỉ xác thực token với các action cần bảo vệ
        $actionsRequireAuth = ['get', 'update', 'delete', 'logout', 'autoGet', 'autoUpdate', 'AdminUpdate', 'muitiInsert'];
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
                    //Verify token với Google
                    $tokenInfo = $this->verifyGoogleToken($access_token);
                    if ($tokenInfo === false) {
                        return [
                            'status' => 'error',
                            'message' => 'Google access token không hợp lệ hoặc đã hết hạn'
                        ];
                    }
                    //Verify email khớp
                    if ($tokenInfo['email'] !== $email) {
                        return [
                            'status' => 'error',
                            'message' => 'Email không khớp với Google token'
                        ];
                    }
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

                    //Block tất cả token cũ của user trước
                    $googleIdForUpdate = $google_id ?? ($user['GoogleID'] ?? null);
                    if ($googleIdForUpdate) {
                        $blockOldTokens = [
                            [
                                'Status' => 'Blocked',
                                'where' => [
                                    'google_id' => $googleIdForUpdate,
                                    'Status' => 'Active'
                                ]
                            ]
                        ];
                        $this->modelSQL->autoUpdate('user_tokens', $blockOldTokens, 'UPDATE_WHERE');
                    }

                    // Tạo token mới (refresh_token)
                    $token = $this->authController->LoginWithGoogle($googleIdForUpdate);

                    // Lưu token mới với trạng thái Active
                    $insertResult = $this->modelSQL->insert('user_tokens', [
                        'google_id' => $googleIdForUpdate,
                        'refresh_token' => $token['refresh_token'],
                        'Status' => 'Active',
                        'expires_at' => $expires_at
                    ]);

                    if (!$insertResult) {
                        return [
                            'status' => 'error',
                            'message' => 'Lưu access token thất bại'
                        ];
                    }

                    if (isset($token['error']) || !$token['refresh_token']) {
                        return [
                            'status' => 'error',
                            'message' => $token['error'] ?? 'Tạo token thất bại'
                        ];
                    }
                    $accessToken = $this->jwtHandler->createAccessToken($user['email'], $user['role'], $user['id'], $user['FullName']);
                    return [
                        'status' => 'success',
                        'token' => $accessToken,
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
                    //Verify token với Google
                    $tokenInfo = $this->verifyGoogleToken($access_token);
                    if ($tokenInfo === false) {
                        return [
                            'status' => 'error',
                            'message' => 'Google access token không hợp lệ hoặc đã hết hạn'
                        ];
                    }
                    //Verify email khớp
                    if ($tokenInfo['email'] !== $email) {
                        return [
                            'status' => 'error',
                            'message' => 'Email không khớp với Google token'
                        ];
                    }
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
                    if (isset($existingUser['role']) && $existingUser['role'] === 'student') {
                        $exists = $this->dataController->getData('student', ['IdAccount' => $existingUser['id']]);
                        if (!$exists) {
                            $this->dataController->addData('student', [
                                'IdAccount' => $existingUser['id'],
                                'Name' => $existingUser['FullName'] ?? $full_name
                            ]);
                        }
                    }
                    if (isset($existingUser['role']) && $existingUser['role'] === 'teacher') {
                        $exists = $this->dataController->getData('teacher', ['IdAccount' => $existingUser['id']]);
                        if (!$exists) {
                            $this->dataController->addData('teacher', [
                                'IdAccount' => $existingUser['id'],
                                'Name' => $existingUser['FullName'] ?? $full_name
                            ]);
                        }
                    }
                    if (isset($existingUser['role']) && $existingUser['role'] === 'admin') {
                        $exists = $this->dataController->getData('admin', ['IdAccount' => $existingUser['id']]);
                        if (!$exists) {
                            $this->dataController->addData('admin', [
                                'IdAccount' => $existingUser['id'],
                                //'Name' => $existingUser['FullName']
                            ]);
                        }
                    }

                    //Block tất cả token cũ của user trước
                    $googleIdForUpdate = $google_id ?? ($existingUser['GoogleID'] ?? null);
                    if ($googleIdForUpdate) {
                        $blockOldTokens = [
                            [
                                'Status' => 'Blocked',
                                'where' => [
                                    'google_id' => $googleIdForUpdate,
                                    'Status' => 'Active'
                                ]
                            ]
                        ];
                        $this->modelSQL->autoUpdate('user_tokens', $blockOldTokens, 'UPDATE_WHERE');
                    }

                    //Tạo JWT refresh token TRƯỚC
                    $token = $this->authController->LoginWithGoogle($googleIdForUpdate);

                    if (isset($token['error']) || !$token['refresh_token']) {
                        return [
                            'status' => 'error',
                            'message' => $token['error'] ?? 'Tạo token thất bại'
                        ];
                    }

                    //Lưu JWT refresh token vào user_tokens 
                    $insertResult = $this->modelSQL->insert('user_tokens', [
                        'google_id' => $googleIdForUpdate,
                        'refresh_token' => $token['refresh_token'],  // ← JWT refresh token
                        'Status' => 'Active',
                        'expires_at' => $expires_at
                    ]);

                    if (!$insertResult) {
                        return [
                            'status' => 'error',
                            'message' => 'Lưu refresh token thất bại'
                        ];
                    }

                    //Tạo JWT access token để trả về cho app
                    $accessToken = $this->jwtHandler->createAccessToken(
                        $existingUser['email'],
                        $existingUser['role'],
                        $existingUser['id'],
                        $existingUser['FullName']
                    );

                    return [
                        'status' => 'success',
                        'token' => $accessToken,
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
                if ($table === 'account') {
                    // Chỉ cho phép teacher và student xem dữ liệu của chính mình
                    if ($params['role'] === 'student') {
                        $conditions = ['email' => $params['email']];
                    } elseif ($params['role'] === 'admin') {
                        if ($params['scope'] === 'self') {
                            $conditions = ['email' => $params['email']];
                        } elseif ($params['scope'] === 'all') {
                            $conditions = [];
                        } elseif (empty($params['scope'])) {
                            $conditions = array_filter($params, fn($key) => !in_array($key, ['table', 'action', 'csrf_token', 'role', 'GoogleID']), ARRAY_FILTER_USE_KEY);
                        } else {
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
                    } else if ($params['role'] === 'teacher') {
                        $conditions = ['email' => $params['email']];
                    } else {
                        http_response_code(403);
                        return [
                            'status' => 'error',
                            'message' => 'Permission denied'
                        ];
                    }
                }
                $data = $this->dataController->getData($table, $conditions, $columns, $orderBy, $limit);
                if (isset($data[0]['GoogleID'])) {
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
                // ✅ Rate limit: Max 50 creations/phút
                $userId = $this->getUserIdFromParams($params);
                if ($userId && !$this->rateLimiter->check('add:' . $userId, 2, 60)) {
                    http_response_code(429);
                    return [
                        'status' => 'error',
                        'message' => 'Bạn tạo dữ liệu quá nhanh. Vui lòng chậm lại.'
                    ];
                }
                $table = $params['table'] ?? 'account';
                $data = array_filter($params, fn($key) => !in_array($key, ['table', 'action', 'csrf_token']), ARRAY_FILTER_USE_KEY);
                $data['role'] = $data['role'] ?? 'customer';
                if (!empty($data)) {
                    if (isset($data['email'])) {
                        if ($this->dataController->getData($table, ['email' => $data['email']])) {
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
                // ✅ Rate limit: Max 50 updates/phút
                $userId = $this->getUserIdFromParams($params);
                if ($userId && !$this->rateLimiter->check('AdminUpdate:' . $userId, 2, 60)) {
                    http_response_code(429);
                    return [
                        'status' => 'error',
                        'message' => 'Bạn cập nhật quá nhanh. Vui lòng chậm lại.'
                    ];
                }
                $table = $params['table'] ?? 'account';
                $id = $params['id'] ?? null;
                $email = $params['emailUpdate'] ?? null;
                $adminEmail = $params['email'] ?? null;
                $params['email'] = $params['emailUpdate'] ?? null;
                $adminRoles = $params['role'] ?? null;
                $params['role'] = $params['roleUpdate'] ?? null;
                // Lấy dữ liệu cần cập nhật (loại bỏ các key không phải cột)
                $data = array_filter($params, fn($key) => !in_array($key, ['table', 'action', 'csrf_token', 'GoogleID', 'emailUpdate', 'roleUpdate']), ARRAY_FILTER_USE_KEY);

                // Xây dựng điều kiện tìm bản ghi
                $conditions = [];

                // 1. Ưu tiên dùng Id (cho classes, teacher, student)
                if (!empty($params['id'])) {
                    $conditions['id'] = $params['id'];
                } elseif (!empty($params['Id'])) {
                    $conditions['Id'] = $params['Id'];
                } elseif (!empty($params['Id'])) {
                    $conditions['Id'] = $params['Id'];
                }
                // 2. Nếu không có Id và là bảng account → dùng email
                elseif ($table === 'account' && !empty($email)) {
                    $conditions['email'] = $email;
                }
                // 3. Trường hợp lỗi
                else {
                    return [
                        'status' => 'error',
                        'message' => 'Thiếu Id (cho lớp/GV/HS) hoặc email (cho tài khoản)',
                        'params' => $params,
                        'adminRoles' => $adminRoles,
                        'adminEmail' => $adminEmail,
                        'data' => $data

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
                    'message' => 'Cập nhật thất bại',
                    'conditions' => $conditions,
                    'data' => $data,
                    'params' => $params,
                    'adminEmail' => $adminEmail
                ];
            case 'update':
                // ✅ Rate limit: Max 50 updates/phút
                $userId = $this->getUserIdFromParams($params);
                if ($userId && !$this->rateLimiter->check('update:' . $userId, 50, 60)) {
                    http_response_code(429);
                    return [
                        'status' => 'error',
                        'message' => 'Bạn cập nhật quá nhanh. Vui lòng chậm lại.'
                    ];
                }
                if ($params['role'] === 'student' && $params['table'] === 'account') {
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
                } else {
                    http_response_code(403);
                    return [
                        'status' => 'error',
                        'message' => 'Chỉ khách hàng mới có quyền này'
                    ];
                }

            case 'delete':
                // ✅ Rate limit: Max 20 deletes/phút (nghiêm hơn vì xóa nguy hiểm)
                $userId = $this->getUserIdFromParams($params);
                if ($userId && !$this->rateLimiter->check('delete:' . $userId, 20, 60)) {
                    http_response_code(429);
                    return [
                        'status' => 'error',
                        'message' => 'Bạn xóa quá nhiều. Vui lòng kiểm tra lại.'
                    ];
                }
                $table = $params['table'] ?? 'account';
                if ($table === 'classes' || $table === 'teacher' || $table === 'student') {
                    $conditions = ['Id' => $params['Id'] ?? null];
                } else if ($table === 'account') {
                    $conditions = ['id' => $params['Id'] ?? null];
                } else {
                    $conditions = array_filter($params, fn($key) => !in_array($key, ['table', 'action', 'csrf_token', 'email', 'roles']), ARRAY_FILTER_USE_KEY);
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
                $email = $params['email'] ?? '';
                $currentToken = $params['current_token'] ?? null;

                error_log("🔄 Refresh token request - Email: $email, Has current_token: " . ($currentToken ? 'yes' : 'no'));

                if (!$email) {
                    return [
                        'status' => 'error',
                        'message' => 'Thiếu email'
                    ];
                }

                // ✅ Ưu tiên: verify JWT từ app (trong body) - không check exp
                if ($currentToken) {
                    $tokenInfo = $this->jwtHandler->verifyTokenToGetOldMail($currentToken);
                    error_log("🔍 Extracted email from token: " . ($tokenInfo ?? 'null') . ", Matches request email: " . ($tokenInfo === $email ? 'yes' : 'no'));

                    if ($tokenInfo && $tokenInfo === $email) {
                        // Lấy thông tin user từ DB
                        $user = $this->authController->GetUserByEmail($email);
                        error_log("👤 User from DB: " . ($user ? json_encode($user) : 'null'));

                        if ($user) {
                            $newToken = $this->jwtHandler->createAccessToken(
                                $user['email'],
                                $user['role'],
                                $user['id'],
                                $user['FullName']
                            );
                            error_log("✅ Generated new token for app: " . substr($newToken, 0, 50) . "...");
                            return [
                                'status' => 'success',
                                'token' => $newToken,
                                'message' => 'Làm mới token thành công từ app'
                            ];
                        }
                    }
                }

                // ✅ Fallback: query refresh_token từ DB (cho web)
                $tables = ['account', 'user_tokens'];
                $columns = ['user_tokens.*', 'account.role', 'account.id', 'account.FullName'];
                $join = [
                    [
                        'type' => 'INNER',
                        'on' => ['account.GoogleID = user_tokens.google_id']
                    ]
                ];
                $conditions = [
                    'account.email' => $email,
                    'user_tokens.Status' => 'Active'
                ];
                $result = $this->modelSQL->autoQuery($tables, $columns, $join, $conditions, []);

                $data = [];
                if ($result instanceof mysqli_result) {
                    while ($row = $result->fetch_assoc()) {
                        $data[] = $row;
                    }
                } else {
                    $data = $result;
                }

                if (!empty($data)) {
                    foreach ($data as $row) {
                        if (
                            isset($row['refresh_token']) &&
                            $row['Status'] === 'Active' &&
                            isset($row['expires_at']) &&
                            strtotime($row['expires_at']) >= time()
                        ) {

                            // Verify refresh_token từ DB
                            $getInfo = $this->jwtHandler->verifyToken($row['refresh_token']);
                            if (!$getInfo) {
                                continue;
                            }

                            $newToken = $this->jwtHandler->createAccessToken(
                                $email,
                                $row['role'],
                                $row['id'],
                                $row['FullName']
                            );

                            return [
                                'status' => 'success',
                                'token' => $newToken,
                                'message' => 'Làm mới token thành công từ web'
                            ];
                        }
                    }
                }

                return [
                    'status' => 'error',
                    'message' => 'Không tìm thấy token hợp lệ hoặc đã hết hạn'
                ];

            case 'logout':
                $email = $params['email'] ?? null;

                if (!$email) {
                    return [
                        'status' => 'error',
                        'message' => 'Không tìm thấy email'
                    ];
                }

                // Lấy thông tin user để có google_id
                $user = $this->authController->GetUserByEmail($email);
                $google_id = $user['GoogleID'] ?? null;

                if (!$google_id) {
                    return [
                        'status' => 'error',
                        'message' => 'Không tìm thấy Google ID của tài khoản'
                    ];
                }

                // Sử dụng autoUpdate để block tất cả token của user
                $dataUpdate = [
                    [
                        'Status' => 'Blocked',
                        'where' => [
                            'google_id' => $google_id
                        ]
                    ]
                ];

                $result = $this->modelSQL->autoUpdate('user_tokens', $dataUpdate, 'UPDATE_WHERE');

                if ($result['status'] === 'success') {
                    return [
                        'status' => 'success',
                        'message' => 'Đăng xuất thành công'
                    ];
                }

                return [
                    'status' => 'error',
                    'message' => 'Đăng xuất thất bại',
                    'details' => $result['message'] ?? 'Unknown error'
                ];

            case 'autoGet':
                $method = $params['method'] ?? '';
                $tables = $params['table'] ?? '';
                $columns = $params['columns'] ?? ['*'];
                $join = $params['join'] ?? [];
                if (isset($params['where']) && is_array($params['where'])) {
                    // Giữ nguyên nếu đã là mảng
                    $conditions = $params['where'];
                } else {
                    $conditions = $params['conditions'] ?? [];
                }
                //$conditions = $params['conditions'] ?? [];
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
                // ✅ Rate limit: Max 50 updates/phút
                $userId = $this->getUserIdFromParams($params);
                if ($userId && !$this->rateLimiter->check('autoUpdate:' . $userId, 2, 60)) {
                    http_response_code(429);
                    return [
                        'status' => 'error',
                        'message' => 'Bạn cập nhật quá nhanh. Vui lòng chậm lại.'
                    ];
                }
                $table = $params['table'] ?? '';
                $data = $params['data'] ?? [];
                $method = $params['method'] ?? 'UPSERT';

                $result = $this->modelSQL->autoUpdate($table, $data, $method);
                return [
                    'status' => $result['status'],
                    'message' => $result['message']
                ];
            case 'multiInsert':
                // ✅ Rate limit: Max 10 bulk operations/phút
                $userId = $this->getUserIdFromParams($params);
                if ($userId && !$this->rateLimiter->check('bulk:' . $userId, 10, 60)) {
                    http_response_code(429);
                    return [
                        'status' => 'error',
                        'message' => 'Bạn thực hiện thao tác hàng loạt quá nhanh.'
                    ];
                }
                $operations = $params['operations'] ?? [];
                // debug log
                file_put_contents(__DIR__ . '/../../multi_insert_debug.log', date('c') . " multiInsert payload: "
                    . json_encode($operations) . "\n", FILE_APPEND);
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
    // ==========================================
    // Helper method để lấy userId
    // ==========================================
    private function getUserIdFromParams($params)
    {
        // Thử lấy từ email (sau khi auth)
        if (isset($params['email'])) {
            $user = $this->authController->GetUserByEmail($params['email']);
            return $user['id'] ?? null;
        }

        // Thử lấy từ GoogleID
        if (isset($params['GoogleID'])) {
            $user = $this->authController->GetUserIdByGoogleId($params['GoogleID']);
            return $user['id'] ?? null;
        }

        // Fallback: dùng IP nếu chưa login
        return $_SERVER['REMOTE_ADDR'];
    }
}
