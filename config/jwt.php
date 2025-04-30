<?php
return [
    'secret_key' => 'Khoa010103',  // 🔒 Thay bằng secret key mạnh hơn, có thể đặt trong .env
    'algorithm' => 'HS256',                   // 🔐 Thuật toán mã hóa JWT
    'issuer' => 'API_Secret',              // 🏷️ Tên ứng dụng của bạn
    'audience' => 'user',           // 👥 Đối tượng sử dụng JWT
    'expiration_time' => 3600,                // ⏳ Thời gian hết hạn (1 giờ)
];
