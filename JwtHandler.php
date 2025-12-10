<?php
//require_once 'config/jwt.php';
require_once __DIR__ . '/config/jwt.php';
if (!class_exists('JwtHandler')) {
    class JwtHandler {
        private $secret;
        private $config;
        
        public function __construct()
        {   
            $this->config = require __DIR__. '/config/jwt.php';
            $this->secret = $this->config['secret_key'];
        }
        
        // Tạo access token (hết hạn 1 phút)
        public function createAccessToken($email, $role, $id, $fullname)
        {
            $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
            $payload = json_encode([
                "iss" => "API_Security",
                "aud" => "user",
                "iat" => time(),
                "exp" => time() + $this->config['access_token_expiration'],
                "type" => "access",
                "data" =>[
                    "email" => $email,
                    "role" => $role,
                    "id" => $id,
                    "FullName" => $fullname
                ],
            ]);

            $headerBase64 = $this->base64UrlEncode($header);
            $payloadBase64 = $this->base64UrlEncode($payload);
            $signature = hash_hmac('sha256', "$headerBase64.$payloadBase64", $this->secret, true);
            $signatureBase64 = $this->base64UrlEncode($signature);
            
            return "$headerBase64.$payloadBase64.$signatureBase64";
        }
        
        // Tạo refresh token (hết hạn 1 giờ)
        public function createRefreshToken($email, $role, $id, $fullname)
        {
            $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
            $payload = json_encode([
                "iss" => "API_Security",
                "aud" => "user",
                "iat" => time(),
                "exp" => time() + $this->config['refresh_token_expiration'],
                "type" => "refresh",
                "data" =>[
                    "email" => $email,
                    "role" => $role,
                    "id" => $id,
                    "FullName" => $fullname
                ],
            ]);

            $headerBase64 = $this->base64UrlEncode($header);
            $payloadBase64 = $this->base64UrlEncode($payload);
            $signature = hash_hmac('sha256', "$headerBase64.$payloadBase64", $this->secret, true);
            $signatureBase64 = $this->base64UrlEncode($signature);
            
            return "$headerBase64.$payloadBase64.$signatureBase64";
        }
        
        // Giữ lại method cũ để tương thích ngược
        public function createToken($email, $role, $id, $fullname)
        {
            return $this->createAccessToken($email, $role, $id, $fullname);
        }

        public function verifyToken($jwt){
            $parts = explode(".", $jwt);
            if(count($parts) !== 3) return null;

            list($headerBase64, $payloadBase64, $signatureBase64) = $parts;
            $expectedSignature = hash_hmac('sha256', "$headerBase64.$payloadBase64", $this->secret, true);
            $expectedSignatureBase64 = $this->base64UrlEncode($expectedSignature);

            if(!hash_equals($signatureBase64, $expectedSignatureBase64)) return null;
            $payload = json_decode($this->base64UrlDecode($payloadBase64), true);
            return ($payload['exp'] > time()) ? $payload : null;
        }

        private function base64UrlEncode($data){
            return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
        }

        private function base64UrlDecode($data){
            return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
        }
    }
}
?>