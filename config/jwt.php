<?php
return [
    'secret_key' => 'Khoa010103',
    'algorithm' => 'HS256',                   //Thuật toán mã hóa JWT
    'issuer' => 'API_Secret',              //Tên ứng dụng 
    'audience' => 'user',           //Đối tượng sử dụng JWT
    'expiration_time' => 3600,                //Thời gian hết hạn (1 giờ)
];
