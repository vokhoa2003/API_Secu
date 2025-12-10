<?php
return [
    'secret_key' => 'Khoa010103',  // 🔒 Thay bằng secret key mạnh hơn, có thể đặt trong .env
    'algorithm' => 'HS256',                   // 🔐 Thuật toán mã hóa JWT
    'issuer' => 'API_Secret',              // 🏷️ Tên ứng dụng của bạn
    'audience' => 'user',           // 👥 Đối tượng sử dụng JWT
    'access_token_expiration' => 60,          // ⏳ Access token hết hạn sau 1 phút
    'refresh_token_expiration' => 3600,       // ⏳ Refresh token hết hạn sau 1 giờ
];
