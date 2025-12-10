# Token System Implementation - Quick Reference

## 📋 Tổng Quan Các Thay Đổi

### Files Đã Sửa Đổi:

1. ✅ `config/jwt.php` - Cấu hình thời gian token
2. ✅ `JwtHandler.php` - Thêm methods tạo access & refresh token
3. ✅ `app/Controller/AuthController.php` - Cập nhật LoginWithGoogle
4. ✅ `app/Controller/ApiController.php` - Cập nhật login cases và thêm refresh_token endpoint
5. ✅ `app/Middleware/AuthMiddleware.php` - Cập nhật verify token từ cookie

### Files Mới Tạo:

1. ✅ `TOKEN_SYSTEM_README.md` - Tài liệu chi tiết
2. ✅ `examples/api-client.js` - JavaScript client library
3. ✅ `examples/test-token-system.html` - Trang test

---

## 🔧 Chi Tiết Thay Đổi

### 1. Config JWT (`config/jwt.php`)

```php
// CŨ:
'expiration_time' => 3600,  // 1 giờ cho tất cả

// MỚI:
'access_token_expiration' => 60,     // 1 phút
'refresh_token_expiration' => 3600,  // 1 giờ
```

### 2. JwtHandler (`JwtHandler.php`)

**Thêm Methods Mới:**

- `createAccessToken()` - Tạo access token (1 phút)
- `createRefreshToken()` - Tạo refresh token (1 giờ)

**Token Structure:**

```json
{
  "iss": "API_Security",
  "aud": "user",
  "iat": 1702200000,
  "exp": 1702200060,
  "type": "access", // hoặc "refresh"
  "data": {
    "email": "user@example.com",
    "role": "student",
    "id": 123,
    "FullName": "User Name"
  }
}
```

### 3. AuthController (`app/Controller/AuthController.php`)

**LoginWithGoogle() - Thay Đổi:**

```php
// CŨ: Trả về 1 token
return ["status" => "success", "token" => $token];

// MỚI: Trả về cả access & refresh token
return [
    "status" => "success",
    "access_token" => $accessToken,
    "refresh_token" => $refreshToken,
    "user" => $user
];
```

### 4. ApiController (`app/Controller/ApiController.php`)

#### A. Login Case - Thay Đổi Chính:

**CŨ:**

```php
// Lưu Google access token vào DB
$this->modelSQL->insert('user_tokens', [
    'google_id' => $google_id,
    'refresh_token' => $access_token,  // Google token
    'expires_at' => $expires_at
]);

// Trả về 1 token
return ['status' => 'success', 'token' => $token['token']];
```

**MỚI:**

```php
// Tạo JWT tokens
$tokenResult = $this->authController->LoginWithGoogle($google_id);

// Lưu refresh token vào DB với Status Active
$this->modelSQL->insert('user_tokens', [
    'google_id' => $google_id,
    'refresh_token' => $tokenResult['refresh_token'],  // JWT refresh token
    'Status' => 'Active',
    'expires_at' => date('Y-m-d H:i:s', time() + 3600)  // 1 giờ
]);

// Đặt access token vào cookie
setcookie('access_token', $tokenResult['access_token'], [
    'expires' => time() + 60,  // 1 phút
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Strict'
]);

// Trả về access token (cũng có trong cookie)
return [
    'status' => 'success',
    'access_token' => $tokenResult['access_token']
];
```

#### B. Endpoint Mới: `refresh_token`

**Workflow:**

1. Lấy email từ access token cũ (đã hết hạn)
2. Query user từ email
3. Lấy refresh token từ DB theo google_id
4. Kiểm tra:
   - Status = 'Active'
   - expires_at chưa quá hạn
   - Verify JWT refresh token
5. Nếu OK → Tạo access token mới và set cookie
6. Nếu FAIL → Xóa token khỏi DB và yêu cầu login lại

**Response Success:**

```json
{
  "status": "success",
  "message": "Làm mới token thành công",
  "access_token": "new_jwt_token"
}
```

**Response Failed:**

```json
{
  "status": "error",
  "message": "Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại."
}
```

#### C. Logout - Thay Đổi:

**Thêm:**

```php
// Xóa access token khỏi cookie
setcookie('access_token', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Strict'
]);
```

### 5. AuthMiddleware (`app/Middleware/AuthMiddleware.php`)

**Thay Đổi Chính:**

```php
// CŨ: Chỉ lấy từ Authorization header
$headers = getallheaders();
$token = $headers["Authorization"];

// MỚI: Ưu tiên cookie, fallback header
$token = null;

// 1. Thử lấy từ cookie trước
if (isset($_COOKIE['access_token'])) {
    $token = $_COOKIE['access_token'];
}
// 2. Nếu không có, thử Authorization header
else if(isset($headers["Authorization"])) {
    $token = str_replace('Bearer ', '', $headers["Authorization"]);
}
```

**Xử Lý refresh_token Action:**

```php
if($result === null){
    // Nếu action là refresh_token, cho phép decode token hết hạn
    if ($action === 'refresh_token') {
        // Decode không verify để lấy email
        $payload = decode_jwt_payload($token);
        return $payload['data'];
    }
    return ["error" => "Invalid or expired access token"];
}
```

---

## 📊 Database Schema

### Bảng `user_tokens`:

```sql
CREATE TABLE `user_tokens` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `google_id` varchar(255) NOT NULL,
  `refresh_token` text NOT NULL,           -- JWT refresh token (không phải Google token)
  `Status` enum('Active','Blocked') DEFAULT 'Active',
  `expires_at` datetime NOT NULL,          -- Thời gian hết hạn refresh token
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `google_id` (`google_id`)
);
```

---

## 🔄 Flow Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                        LOGIN FLOW                            │
└─────────────────────────────────────────────────────────────┘

1. Client → API: POST /index.php?action=login
   Body: { email, FullName, GoogleID, ... }

2. API → AuthController: LoginWithGoogle(googleID)
   ↓
3. AuthController → JwtHandler:
   - createAccessToken() → JWT (1 min)
   - createRefreshToken() → JWT (1 hour)
   ↓
4. API → Database: INSERT user_tokens
   {
     google_id,
     refresh_token: JWT refresh token,
     Status: 'Active',
     expires_at: NOW() + 1 hour
   }
   ↓
5. API → Cookie: Set access_token (1 min, httponly)
   ↓
6. API → Client: { status: "success", access_token }

┌─────────────────────────────────────────────────────────────┐
│                    API REQUEST FLOW                          │
└─────────────────────────────────────────────────────────────┘

1. Client → API: POST /index.php?action=get
   Cookie: access_token=jwt_token

2. API → Middleware: verifyRequest()
   ↓
3. Middleware: Read token from Cookie
   ↓
4. Middleware → JwtHandler: verifyToken()
   ↓
5a. Token Valid → Continue request
5b. Token Expired → Return 401 error

┌─────────────────────────────────────────────────────────────┐
│                   REFRESH TOKEN FLOW                         │
└─────────────────────────────────────────────────────────────┘

1. Client → API: POST /index.php?action=refresh_token
   Cookie: access_token=expired_jwt

2. API → Middleware: Allow expired token for refresh action
   ↓
3. API: Extract email from expired token
   ↓
4. API → Database: SELECT * FROM user_tokens WHERE google_id
   ↓
5. API: Verify:
   - Status = 'Active'
   - expires_at > NOW()
   - JWT signature valid
   ↓
6a. Valid → Create new access token
    → Set cookie
    → Return { status: "success", access_token }

6b. Invalid → Delete token from DB
    → Return { status: "error", message: "Please login again" }

┌─────────────────────────────────────────────────────────────┐
│                      LOGOUT FLOW                             │
└─────────────────────────────────────────────────────────────┘

1. Client → API: POST /index.php?action=logout
   Cookie: access_token=jwt

2. API → Database: DELETE FROM user_tokens WHERE google_id
   ↓
3. API → Cookie: Delete access_token
   ↓
4. API → Client: { status: "success" }
```

---

## 🧪 Testing

### Manual Test với cURL:

**1. Login:**

```bash
curl -X POST "http://localhost/API_Secu/index.php?action=login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@example.com",
    "FullName": "Test User",
    "GoogleID": "test123",
    "access_token": "dummy_token",
    "expires_at": "2025-12-11 10:00:00"
  }' \
  -c cookies.txt -v
```

**2. Get Data (với cookie):**

```bash
curl -X POST "http://localhost/API_Secu/index.php?action=get&table=account&scope=self" \
  -b cookies.txt -v
```

**3. Đợi 1 phút, test refresh:**

```bash
sleep 61
curl -X POST "http://localhost/API_Secu/index.php?action=refresh_token" \
  -b cookies.txt -c cookies.txt -v
```

**4. Get Data lại (với token mới):**

```bash
curl -X POST "http://localhost/API_Secu/index.php?action=get&table=account&scope=self" \
  -b cookies.txt -v
```

**5. Logout:**

```bash
curl -X POST "http://localhost/API_Secu/index.php?action=logout" \
  -b cookies.txt -v
```

### Test với Browser:

1. Mở `http://localhost/API_Secu/examples/test-token-system.html`
2. Nhấn "Login" để tạo phiên
3. Nhấn "Get Data" để test API
4. Nhấn "Đợi 60s và Refresh" để test refresh flow
5. Nhấn "Logout" để đăng xuất

---

## ⚠️ Lưu Ý Quan Trọng

### 1. Cookie Security

- **httponly**: Không thể truy cập từ JavaScript (chống XSS)
- **samesite=Strict**: Chỉ gửi với same-site requests (chống CSRF)
- **Secure flag**: Nên thêm khi deploy production (HTTPS only)

### 2. Token Expiration

- Access token: 1 phút (có thể điều chỉnh trong config)
- Refresh token: 1 giờ (có thể điều chỉnh trong config)
- Khuyến nghị production:
  - Access: 5-15 phút
  - Refresh: 7-30 ngày

### 3. Database Maintenance

Nên có cronjob xóa refresh tokens hết hạn:

```sql
DELETE FROM user_tokens WHERE expires_at < NOW();
```

### 4. Blocking Users

Để khóa phiên của user:

```sql
UPDATE user_tokens SET Status = 'Blocked' WHERE google_id = 'user_id';
```

### 5. CORS

Nếu frontend ở domain khác, cần cấu hình CORS:

```php
header('Access-Control-Allow-Origin: https://your-domain.com');
header('Access-Control-Allow-Credentials: true');
```

---

## 🐛 Troubleshooting

### Token không được lưu trong cookie?

- Kiểm tra `credentials: 'include'` trong fetch
- Kiểm tra CORS headers
- Kiểm tra cookie path

### Refresh token luôn trả về lỗi?

- Kiểm tra database có record trong user_tokens
- Kiểm tra Status = 'Active'
- Kiểm tra expires_at > NOW()
- Check JWT signature

### Access token hết hạn quá nhanh?

- Tăng `access_token_expiration` trong config/jwt.php
- Hoặc implement auto-refresh ở client

---

## 📝 Checklist Deploy Production

- [ ] Thay đổi `secret_key` trong config/jwt.php (dùng .env)
- [ ] Thêm `Secure` flag cho cookie (HTTPS only)
- [ ] Cấu hình CORS đúng domain
- [ ] Tăng thời gian access token lên 5-15 phút
- [ ] Tăng thời gian refresh token lên 7-30 ngày
- [ ] Setup cronjob dọn dẹp tokens hết hạn
- [ ] Enable error logging
- [ ] Implement rate limiting
- [ ] Backup database schema
- [ ] Test toàn bộ flow trên staging environment
