# Hệ thống Token Mới - Access Token & Refresh Token

## Tổng Quan

Hệ thống token mới sử dụng hai loại token:

- **Access Token**: Thời hạn 1 phút, lưu trong cookie, dùng để xác thực các request
- **Refresh Token**: Thời hạn 1 giờ, lưu trong database, dùng để cấp access token mới

## Cấu Hình

### 1. Thời gian hết hạn token (config/jwt.php)

```php
'access_token_expiration' => 60,      // 1 phút
'refresh_token_expiration' => 3600,   // 1 giờ
```

### 2. Bảng database (user_tokens)

- `google_id`: ID Google của người dùng
- `refresh_token`: JWT refresh token
- `Status`: Trạng thái token ('Active' hoặc 'Blocked')
- `expires_at`: Thời gian hết hạn của refresh token

## Luồng Hoạt Động

### A. Đăng Nhập (login / app_login)

1. **Request đăng nhập** với thông tin Google OAuth
2. **Hệ thống kiểm tra** user trong database
3. **Tạo tokens**:
   - Access token (1 phút)
   - Refresh token (1 giờ)
4. **Lưu trữ**:
   - Refresh token → Database (bảng `user_tokens`) với Status = 'Active'
   - Access token → Cookie HTTP-only
5. **Response**: Trả về thông tin đăng nhập thành công

### B. Truy Vấn API (Protected Endpoints)

1. **Client gửi request** đến endpoint cần bảo vệ
2. **Middleware kiểm tra**:
   - Lấy access token từ cookie (hoặc Authorization header)
   - Verify access token
3. **Nếu token hợp lệ**: Cho phép truy cập
4. **Nếu token hết hạn**: Trả về lỗi 401

### C. Làm Mới Token (refresh_token endpoint)

**Khi nào sử dụng**: Khi access token hết hạn (sau 1 phút)

**Quy trình**:

1. **Client gọi** endpoint `refresh_token` với access token cũ (đã hết hạn)
2. **Middleware cho phép** request với token hết hạn để lấy email
3. **Hệ thống kiểm tra**:
   - Lấy thông tin user từ email
   - Tìm refresh token trong database
   - Kiểm tra Status = 'Active'
   - Kiểm tra expires_at chưa quá hạn
   - Verify refresh token JWT
4. **Nếu hợp lệ**:
   - Tạo access token mới (1 phút)
   - Lưu vào cookie
   - Trả về access token mới
5. **Nếu không hợp lệ**:
   - Xóa refresh token khỏi database
   - Yêu cầu đăng nhập lại

### D. Đăng Xuất (logout)

1. **Client gọi** endpoint `logout`
2. **Hệ thống thực hiện**:
   - Xóa refresh token khỏi database
   - Xóa access token khỏi cookie
3. **Response**: Đăng xuất thành công

## API Endpoints

### 1. Login

```
POST /API_Secu/index.php?action=login
```

**Request Body:**

```json
{
  "email": "user@example.com",
  "FullName": "User Name",
  "GoogleID": "google_id_here",
  "access_token": "google_access_token",
  "expires_at": "2025-12-10 15:00:00"
}
```

**Response:**

```json
{
  "status": "success",
  "message": "Đăng nhập thành công",
  "access_token": "eyJhbGc..."
}
```

_Note: Access token cũng được lưu trong cookie_

### 2. Refresh Token

```
POST /API_Secu/index.php?action=refresh_token
```

**Headers:**

```
Cookie: access_token=old_expired_token
```

hoặc

```
Authorization: Bearer old_expired_token
```

**Response (Success):**

```json
{
  "status": "success",
  "message": "Làm mới token thành công",
  "access_token": "new_access_token"
}
```

**Response (Failed - Refresh Token Expired):**

```json
{
  "status": "error",
  "message": "Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại."
}
```

**Response (Failed - Token Blocked):**

```json
{
  "status": "error",
  "message": "Phiên đăng nhập đã bị khóa. Vui lòng đăng nhập lại."
}
```

### 3. Protected Endpoints (get, update, delete, etc.)

```
POST /API_Secu/index.php?action=get&table=account
```

**Headers:**

```
Cookie: access_token=valid_token
```

hoặc

```
Authorization: Bearer valid_token
```

### 4. Logout

```
POST /API_Secu/index.php?action=logout
```

**Headers:**

```
Cookie: access_token=valid_token
```

**Response:**

```json
{
  "status": "success",
  "message": "Đăng xuất thành công"
}
```

## Xử Lý Lỗi Client-Side

### JavaScript Example

```javascript
async function apiRequest(url, options = {}) {
  try {
    let response = await fetch(url, {
      ...options,
      credentials: "include", // Gửi cookies
    });

    // Nếu access token hết hạn (401)
    if (response.status === 401) {
      // Thử refresh token
      const refreshResponse = await fetch(
        "/API_Secu/index.php?action=refresh_token",
        {
          method: "POST",
          credentials: "include",
        }
      );

      if (refreshResponse.ok) {
        // Retry request gốc với token mới
        response = await fetch(url, {
          ...options,
          credentials: "include",
        });
      } else {
        // Refresh token cũng hết hạn - yêu cầu đăng nhập lại
        window.location.href = "/login";
        return;
      }
    }

    return await response.json();
  } catch (error) {
    console.error("API Error:", error);
    throw error;
  }
}

// Sử dụng
apiRequest("/API_Secu/index.php?action=get&table=account&scope=self")
  .then((data) => console.log(data))
  .catch((error) => console.error(error));
```

## Bảo Mật

### 1. Cookie Security

- **HttpOnly**: Không thể truy cập từ JavaScript (chống XSS)
- **SameSite=Strict**: Chống CSRF attacks
- **Expires**: Tự động hết hạn sau 1 phút

### 2. Refresh Token Security

- Lưu trong database với trạng thái Active/Blocked
- Có thời gian hết hạn rõ ràng
- Có thể revoke bằng cách đổi Status thành 'Blocked'

### 3. Token Validation

- Verify signature JWT
- Kiểm tra expiration time
- Kiểm tra token type (access vs refresh)

## Quản Lý Token

### Khóa phiên đăng nhập của user

```sql
UPDATE user_tokens
SET Status = 'Blocked'
WHERE google_id = 'user_google_id';
```

### Xem các phiên đăng nhập đang active

```sql
SELECT google_id, expires_at, Status
FROM user_tokens
WHERE Status = 'Active'
AND expires_at > NOW();
```

### Dọn dẹp tokens hết hạn

```sql
DELETE FROM user_tokens
WHERE expires_at < NOW();
```

## Migration Notes

### Thay đổi từ hệ thống cũ:

1. ✅ Token được chia thành access token (ngắn hạn) và refresh token (dài hạn)
2. ✅ Access token lưu trong cookie thay vì truyền trong body
3. ✅ Refresh token có trạng thái Active/Blocked có thể quản lý
4. ✅ Middleware tự động kiểm tra token từ cookie
5. ✅ Endpoint refresh_token để gia hạn phiên

### Tương thích ngược:

- Vẫn hỗ trợ Authorization header với Bearer token
- Method `createToken()` cũ vẫn hoạt động (gọi `createAccessToken()`)

## Testing

### 1. Test Login

```bash
curl -X POST http://localhost/API_Secu/index.php?action=login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@example.com",
    "FullName": "Test User",
    "GoogleID": "test123",
    "access_token": "google_token",
    "expires_at": "2025-12-10 16:00:00"
  }' \
  -c cookies.txt
```

### 2. Test Protected Endpoint

```bash
curl -X POST http://localhost/API_Secu/index.php?action=get&table=account&scope=self \
  -b cookies.txt
```

### 3. Test Refresh Token (sau 1 phút)

```bash
curl -X POST http://localhost/API_Secu/index.php?action=refresh_token \
  -b cookies.txt \
  -c cookies.txt
```

### 4. Test Logout

```bash
curl -X POST http://localhost/API_Secu/index.php?action=logout \
  -b cookies.txt
```

## Troubleshooting

### Lỗi: "Missing access token"

- Kiểm tra cookie có được gửi trong request
- Đảm bảo `credentials: 'include'` trong fetch

### Lỗi: "Phiên đăng nhập đã hết hạn"

- Refresh token đã hết hạn (> 1 giờ)
- Yêu cầu user đăng nhập lại

### Lỗi: "Token không hợp lệ"

- Token bị sửa đổi
- Secret key không khớp
- Token không đúng format

### Access token hết hạn quá nhanh

- Có thể tăng `access_token_expiration` trong config/jwt.php
- Khuyến nghị: 1-15 phút

### Refresh token hết hạn quá nhanh

- Có thể tăng `refresh_token_expiration` trong config/jwt.php
- Khuyến nghị: 1-24 giờ
