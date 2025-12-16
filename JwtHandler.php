<?php
//require_once 'config/jwt.php';
require_once __DIR__ . '/config/jwt.php';
if (!class_exists('JwtHandler')) {
    class JwtHandler {
        private $secret;
        public function __construct()
        {   
            date_default_timezone_set('Asia/Ho_Chi_Minh');
            $config = require __DIR__. '/config/jwt.php';
            $this->secret = $config['secret_key'];

        }
        public function createRefreshToken($email, $role, $id, $fullname)
        {
            $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
            $payload = json_encode([
                "iss" => "API_Security",
                "aud" => "user",
                "iat" => time(),
                "exp" => time() + 3600,
                "jti" => bin2hex(random_bytes(16)),
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

        public function createAccessToken($email, $role, $id, $fullname)
        {
            $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
            $payload = json_encode([
                "iss" => "API_Security",
                "aud" => "user",
                "iat" => time(),
                "exp" => time() + 30,
                "jti" => bin2hex(random_bytes(16)),
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

        public function verifyTokenToGetOldMail($jwt){
            $parts = explode(".", $jwt);
            if(count($parts) !== 3) return null;

            list($headerBase64, $payloadBase64, $signatureBase64) = $parts;
            $expectedSignature = hash_hmac('sha256', "$headerBase64.$payloadBase64", $this->secret, true);
            $expectedSignatureBase64 = $this->base64UrlEncode($expectedSignature);

            if(!hash_equals($signatureBase64, $expectedSignatureBase64)) return null;
            $payload = json_decode($this->base64UrlDecode($payloadBase64), true);
            return $payload['data']['email'] ?? null;
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