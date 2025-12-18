<?php
// require_once __DIR__ . '/../vendor/autoload.php';
// use Dotenv\Dotenv;
// // Load biến môi trường từ .env
// $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
// $dotenv->load();

// // Khởi tạo Google Client
// $client = new Google_Client();
// $client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
// $client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
// $client->setRedirectUri($_ENV['GOOGLE_REDIRECT_URI']);
// $client->addScope("email");
// $client->addScope("profile");
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Google\Client as Google_Client;

// Load biến môi trường từ .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Kiểm tra biến môi trường có được load không
if (!isset($_ENV['GOOGLE_CLIENT_ID'], $_ENV['GOOGLE_CLIENT_SECRET'], $_ENV['GOOGLE_REDIRECT_URI'])) {
    die("Lỗi: Không tìm thấy biến môi trường. Hãy kiểm tra file .env");
}

// Khởi tạo Google Client
$client = new Google_Client();
$client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
$client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
$client->setRedirectUri($_ENV['GOOGLE_REDIRECT_URI']);
$client->addScope("email");
$client->addScope("profile");

// Xuất object client để dùng trong index.php
return $client;
