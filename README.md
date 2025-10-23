Dự án QuizzApp để xây dựng hệ thống kiểm tra trắc nghiệm cho học sinh

## Hướng dẫn cấu hình:

Đầu tiên các bạn cần clone project:
git clone <https://github.com/vokhoa2003/API_Security.git>
cd vào <API_Security>

Bước 1:
Kiểm tra php đã có chưa: php -v => kết quả mong đợi ví dụ "PHP 8.2.12 (cli) (built: Oct 24 2023 21:15:15) (ZTS Visual C++ 2019 x64)" hoặc "PHP 8.2.12"
Nếu không có thì cài php.

Bước 2:
Truy cập: https://getcomposer.org/download/
Tải Composer-Setup.exe
Chạy file cài → chọn đường dẫn đến php.exe
Sau khi cài xong, mở lại CMD và kiểm tra: composer -V =>Ví du kết quả: Composer version 2.x.x

Bước 3:
Trong thư mục chứa file composer.json, chạy: composer install

Bước 4: Cấu hình .env:

a. Tạo một file .env chưa các thông tin dưới
b. Kết nối đến mySQL XAMPP với các thông tin dưới đây
DBHOST="localhost"
DBUSER=""
DBPASS=""
DBNAME=""

c. Cấu hình khác:
ENCRYPTION_KEY =""
