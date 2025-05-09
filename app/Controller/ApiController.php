<?php
require_once __DIR__ . '/AuthController.php';

class ApiController {
    private $authController;

    public function __construct() {
        $this->authController = new AuthController();
    }

    public function handleRequest($endpoint, $data) {
        if ($endpoint === 'login' && isset($data['GoogleID'])) {
            return $this->authController->LoginWithGoogle($data['GoogleID']);
        }
        if ($endpoint === 'user' && isset($data['token'])) {
            $userData = $this->authController->getBearerToken($data['token']);
            if ($userData) {
                return [
                    'status' => 'success',
                    'data' => $userData
                ];
            }
            return ['error' => 'Invalid token'];
        }
        if ($endpoint === 'get' && isset($data['GoogleID'], $data['role'], $data['csrf_token'])) {
            // Xác thực CSRF token
            if (!isset($_COOKIE['csrf_token']) || $_COOKIE['csrf_token'] !== $data['csrf_token']) {
                return ['error' => 'Invalid CSRF token'];
            }
            // Lấy dữ liệu người dùng từ AuthController
            $user = $this->authController->GetUserIdByGoogleId($data['GoogleID']);
            if ($user && $user['role'] === $data['role']) {
                return [
                    'status' => 'success',
                    'data' => $user
                ];
            }
            return ['message' => 'No data found'];
        }
        return ['error' => 'Endpoint not found', 'debug' => ['error' => 'Endpoint not found']];
    }
}
?>