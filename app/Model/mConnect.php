<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Google\Client as Google_Client;

// Load biến môi trường từ .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

// Kiểm tra biến môi trường có được load không
if (!isset($_ENV['DBNAME'], $_ENV['DBHOST'], $_ENV['DBPASS'], $_ENV['DBUSER'])) {
    die("Lỗi: Không tìm thấy biến môi trường. Hãy kiểm tra file .env");
}
class connect
{
    public function OpenDB()
    {
        $con = new mysqli($_ENV['DBHOST'], $_ENV['DBUSER'], $_ENV['DBPASS'], $_ENV['DBNAME']);
        mysqli_set_charset($con, "UTF8");
        return $con;
    }
    public function closeDB()
    {
        if ($this->OpenDB()) {
            mysqli_close($this->OpenDB());
        }
    }
}
