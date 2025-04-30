<?php
class Encryption {
    private $key;
    private $cipher = 'AES-256-CBC';

    public function __construct($key) {
        $this->key = hash('sha256', $key, true);
    }

    public function encrypt($data) {
        if (is_array($data)) {
            $data = json_encode($data);
        }

        // Tạo IV ngẫu nhiên
        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = openssl_random_pseudo_bytes($ivLength);

        // Mã hóa dữ liệu
        $encrypted = openssl_encrypt($data, $this->cipher, $this->key, 0, $iv);
        if ($encrypted === false) {
            throw new Exception('Encryption failed');
        }

        // Kết hợp IV và dữ liệu mã hóa (IV cần để giải mã sau này)
        $encrypted = base64_encode($iv . $encrypted);
        return $encrypted;
    }

    public function decrypt($encryptedData) {
        // Giải mã base64
        $data = base64_decode($encryptedData);
        if ($data === false) {
            throw new Exception('Invalid encrypted data');
        }

        // Lấy IV và dữ liệu mã hóa
        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);

        // Giải mã dữ liệu
        $decrypted = openssl_decrypt($encrypted, $this->cipher, $this->key, 0, $iv);
        if ($decrypted === false) {
            throw new Exception('Decryption failed');
        }

        // Chuyển JSON thành mảng nếu có
        $result = json_decode($decrypted, true);
        return $result !== null ? $result : $decrypted;
    }
}
?>