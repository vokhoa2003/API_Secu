# 🐛 Debug Token System

## Vấn Đề Đã Được Phát Hiện & Khắc Phục

### 1. Middleware đã được cập nhật

- Cho phép lấy token từ cả `access_token` và `auth_token` cookie
- Xử lý đúng trường hợp refresh_token (cho phép decode token hết hạn)

### 2. Đã thêm logging đầy đủ

- Login flow: log GoogleID, insert result
- Refresh token flow: log email, user, google_id, token record

### 3. Debug Tools

#### A. Debug Web Interface

**URL:** `http://localhost/API_Secu/debug-token.html`

Các tính năng:

- ✅ Test login
- ✅ Check cookies
- ✅ Check token in database
- ✅ Test protected endpoints
- ✅ Test refresh token
- ✅ Test logout

#### B. Debug Database Script

**URL:** `http://localhost/API_Secu/debug-db.php?email=xxx`
hoặc
**URL:** `http://localhost/API_Secu/debug-db.php?google_id=xxx`

Trả về:

```json
{
  "timestamp": "2025-12-10 10:00:00",
  "request": {
    "google_id": "xxx",
    "email": "xxx"
  },
  "data": {
    "account": { ... },
    "user_tokens": { ... },
    "token_status": {
      "expires_at": "...",
      "is_expired": false,
      "time_remaining": 3456,
      "status": "Active"
    },
    "all_tokens": [ ... ]
  }
}
```

## Cách Debug Khi Refresh Token Không Hoạt Động

### Bước 1: Kiểm tra Login Flow

```bash
# 1. Mở debug-token.html
# 2. Nhấn "Login"
# 3. Kiểm tra logs (F12 -> Console hoặc check PHP error_log)
```

Logs sẽ hiển thị:

```
LOGIN: Creating tokens for GoogleID: xxx
LOGIN: Deleting old token for GoogleID: xxx
LOGIN: Inserting new refresh token for GoogleID: xxx
LOGIN: Insert result: SUCCESS/FAILED
```

### Bước 2: Kiểm tra Database

```bash
# Mở browser: http://localhost/API_Secu/debug-db.php?email=test@example.com
```

Kiểm tra:

- ✅ `account.GoogleID` có giá trị
- ✅ `user_tokens.google_id` khớp với `account.GoogleID`
- ✅ `user_tokens.Status` = 'Active'
- ✅ `user_tokens.expires_at` > current time

### Bước 3: Kiểm tra Access Token trong Cookie

```bash
# Trong browser, mở DevTools (F12)
# Application -> Cookies -> localhost
```

Phải thấy:

- `access_token` hoặc `auth_token` với giá trị JWT

### Bước 4: Test Refresh Token

```bash
# 1. Đợi 65 giây (hoặc dùng nút "Force Refresh Now")
# 2. Kiểm tra logs
```

Logs sẽ hiển thị:

```
REFRESH_TOKEN: Email from params: xxx
REFRESH_TOKEN: User from email: {...}
REFRESH_TOKEN: GoogleID: xxx
REFRESH_TOKEN: Querying user_tokens with google_id: xxx
REFRESH_TOKEN: Token record: {...}
```

## Common Issues & Solutions

### Issue 1: "Email không hợp lệ"

**Nguyên nhân:** Middleware không truyền email vào params

**Giải pháp:**

- Kiểm tra AuthMiddleware line 11-12: phải lấy token từ cookie
- Kiểm tra token có decode được email không

**Test:**

```bash
# Decode token manually
curl http://localhost/API_Secu/debug-db.php?email=test@example.com
```

### Issue 2: "GoogleID không tồn tại"

**Nguyên nhân:** Account trong DB không có GoogleID

**Giải pháp:**

```sql
-- Check account
SELECT id, email, GoogleID FROM account WHERE email = 'test@example.com';

-- Nếu GoogleID NULL, update:
UPDATE account SET GoogleID = 'test_google_123' WHERE email = 'test@example.com';
```

### Issue 3: "Phiên đăng nhập đã hết hạn"

**Nguyên nhân:**

- user_tokens không có record
- expires_at < current time
- google_id không khớp

**Giải pháp:**

```sql
-- Check user_tokens
SELECT * FROM user_tokens WHERE google_id = 'test_google_123';

-- Nếu không có, phải login lại
-- Nếu có nhưng hết hạn, phải login lại
```

### Issue 4: "Token không hợp lệ"

**Nguyên nhân:** JWT signature không khớp

**Giải pháp:**

- Kiểm tra `secret_key` trong config/jwt.php phải giống nhau
- Kiểm tra refresh_token trong DB có đúng format JWT không

## Manual SQL Checks

```sql
-- 1. Xem tất cả accounts với GoogleID
SELECT id, email, GoogleID, role, Status
FROM account
WHERE GoogleID IS NOT NULL;

-- 2. Xem tất cả tokens đang active
SELECT * FROM user_tokens
WHERE Status = 'Active'
AND expires_at > NOW();

-- 3. Xem token của user cụ thể
SELECT
    a.email,
    a.GoogleID,
    t.Status,
    t.expires_at,
    TIMESTAMPDIFF(SECOND, NOW(), t.expires_at) as seconds_remaining
FROM account a
LEFT JOIN user_tokens t ON a.GoogleID = t.google_id
WHERE a.email = 'test@example.com';

-- 4. Xóa tất cả tokens (reset)
DELETE FROM user_tokens;

-- 5. Xóa tokens hết hạn
DELETE FROM user_tokens WHERE expires_at < NOW();
```

## Testing Checklist

- [ ] Login thành công
- [ ] Cookie `access_token` được set
- [ ] Database có record trong `user_tokens`
- [ ] `user_tokens.google_id` = `account.GoogleID`
- [ ] `user_tokens.Status` = 'Active'
- [ ] `user_tokens.expires_at` > NOW()
- [ ] Get account data thành công
- [ ] Đợi 65 giây
- [ ] Refresh token thành công
- [ ] Cookie được update với token mới
- [ ] Get account data vẫn thành công sau refresh
- [ ] Logout thành công
- [ ] Cookies được xóa
- [ ] Database token được xóa

## Error Log Location

**Windows XAMPP:**

```
C:\xampp\apache\logs\error.log
C:\xampp\php\logs\php_error_log
```

**Search for:**

```bash
LOGIN:
APP_LOGIN:
REFRESH_TOKEN:
```

## Quick Fix Script

Nếu cần reset toàn bộ:

```sql
-- Reset user_tokens
DELETE FROM user_tokens;

-- Ensure all accounts have GoogleID
UPDATE account
SET GoogleID = CONCAT('google_', id)
WHERE GoogleID IS NULL OR GoogleID = '';
```

## Contact

Nếu vẫn gặp vấn đề, cung cấp:

1. Screenshot logs từ `debug-token.html`
2. Output từ `debug-db.php?email=xxx`
3. PHP error_log content (search "REFRESH_TOKEN:")
