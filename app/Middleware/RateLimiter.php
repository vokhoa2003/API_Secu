<?php
class RateLimiter {
    private function getFilePath($identifier) {
        return sys_get_temp_dir() . '/rate_limit_' . md5($identifier) . '.json';
    }
    
    public function check($identifier, $maxRequests, $timeWindow) {
        $file = $this->getFilePath($identifier);
        
        $data = [];
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true) ?: [];
        }
        
        $now = time();
        
        // Xóa requests cũ
        $data = array_filter($data, function($timestamp) use ($now, $timeWindow) {
            return ($now - $timestamp) < $timeWindow;
        });
        
        // Check limit
        if (count($data) >= $maxRequests) {
            return false;
        }
        
        // Thêm request mới
        $data[] = $now;
        file_put_contents($file, json_encode($data));
        
        return true;
    }
    
    // Helper: Get số requests còn lại
    public function getRemaining($identifier, $maxRequests, $timeWindow) {
        $file = $this->getFilePath($identifier);
        
        if (!file_exists($file)) {
            return $maxRequests;
        }
        
        $data = json_decode(file_get_contents($file), true) ?: [];
        $now = time();
        
        $data = array_filter($data, function($timestamp) use ($now, $timeWindow) {
            return ($now - $timestamp) < $timeWindow;
        });
        
        return max(0, $maxRequests - count($data));
    }
}
?>