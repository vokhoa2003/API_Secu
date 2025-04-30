<?php
//require_once 'config/jwt.php';
require_once __DIR__ . '/config/jwt.php';
if (!class_exists('JwtHandler')) {
    class JwtHandler {
        private $secret;
        public function __construct()
        {   
            $config = require __DIR__. '/config/jwt.php';
            $this->secret = $config['secret_key'];

        }
        public function createToken($google_id, $email, $role, $full_name)
        {
            $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
            $payload = json_encode([
                "iss" => "API_Security",
                "aud" => "user",
                "iat" => time(),
                "exp" => time() + 3600,
                "data" =>[
                    "GoogleID" => $google_id,
                    "role" => $role,
                    "email" => $email,
                    "FullName" => $full_name
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

        private function base64UrlEncode($data){
            return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
        }

        private function base64UrlDecode($data){
            return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
        }
    }
}
?>