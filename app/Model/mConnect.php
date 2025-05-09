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

  class connect {
      private $conn = null;

      public function OpenDB() {
          // Nếu kết nối đã tồn tại và còn sống, trả về kết nối hiện tại
          if ($this->conn && $this->conn->ping()) {
              return $this->conn;
          }

          // Tạo kết nối mới nếu không có hoặc đã mất
          $this->conn = new mysqli($_ENV['DBHOST'], $_ENV['DBUSER'], $_ENV['DBPASS'], $_ENV['DBNAME']);
          if ($this->conn->connect_error) {
              error_log("Connection failed: " . $this->conn->connect_error);
              $this->conn = null;
              return false;
          }
          // Thiết lập múi giờ UTC+7
          $this->conn->query("SET time_zone = '+07:00'");
          mysqli_set_charset($this->conn, "UTF8");
          return $this->conn;
      }

      public function closeDB() {
          // Chỉ đóng nếu kết nối đang tồn tại
          if ($this->conn) {
              $this->conn->close();
              $this->conn = null;
          }
      }
  }
  ?>