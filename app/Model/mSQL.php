<?php
require_once __DIR__ . '/mConnect.php';

class ModelSQL extends Connect {
    /**
     * Thực thi truy vấn SQL tùy chỉnh
     */
    public function executeQuery($sql, $params = [], $types = "") {
        $con = $this->openDB();
        $stmt = $con->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types ?: str_repeat("s", count($params)), ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result() ?: $stmt->affected_rows;
        $stmt->close();
        return $result;
    }

    /**
     * Lấy dữ liệu từ bảng với điều kiện linh hoạt
     */
    public function viewData($table, $conditions = [], $columns = ['*'], $orderBy = '', $limit = '') {
        $con = $this->openDB();
        $columns = implode(", ", $columns);
        $sql = "SELECT $columns FROM $table";
        
        $params = [];
        $types = "";
        if (!empty($conditions)) {
            $where = [];
            foreach ($conditions as $key => $value) {
                $where[] = "$key = ?";
                $params[] = $value;
                $types .= is_int($value) ? "i" : "s";
            }
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        
        if ($orderBy) $sql .= " ORDER BY $orderBy";
        if ($limit) $sql .= " LIMIT $limit";
        
        $result = $this->executeQuery($sql, $params, $types);
        return $result instanceof mysqli_result ? $result : false;
    }

    /**
     * Thêm hoặc cập nhật dữ liệu
     */
    // public function insert($table, $data, $onDuplicateKeyUpdate = true) {
    //     $con = $this->openDB();
    //     $columns = array_keys($data);
    //     $placeholders = array_fill(0, count($data), "?");
    //     $sql = "INSERT INTO $table (" . implode(",", $columns) . ") VALUES (" . implode(",", $placeholders) . ")";
        
    //     if ($onDuplicateKeyUpdate) {
    //         $updates = array_map(fn($key) => "$key=VALUES($key)", $columns);
    //         $sql .= " ON DUPLICATE KEY UPDATE " . implode(", ", $updates);
    //     }
        
    //     $types = str_repeat("s", count($data)); // Có thể cải tiến để hỗ trợ kiểu dữ liệu khác
    //     return $this->executeQuery($sql, array_values($data), $types) !== false;
    // }
    public function insert($table, $data, $onDuplicateKeyUpdate = true) {
        $con = $this->openDB();
        
        // Loại bỏ key trùng lặp, giữ giá trị cuối cùng
        $filteredData = array_combine(
            array_map('strtolower', array_keys($data)),
            array_values($data)
        );
        $filteredData = array_combine(
            array_map('ucfirst', array_keys($filteredData)),
            array_values($filteredData)
        );
        
        if (empty($filteredData)) {
            return false;
        }

        $columns = array_keys($filteredData);
        $placeholders = array_fill(0, count($filteredData), "?");
        $sql = "INSERT INTO $table (" . implode(",", $columns) . ") VALUES (" . implode(",", $placeholders) . ")";
        
        if ($onDuplicateKeyUpdate) {
            $updates = array_map(fn($key) => "$key=VALUES($key)", $columns);
            $sql .= " ON DUPLICATE KEY UPDATE " . implode(", ", $updates);
        }
        
        $types = str_repeat("s", count($filteredData));
        return $this->executeQuery($sql, array_values($filteredData), $types) !== false;
    }
    /**
     * Cập nhật dữ liệu
     */
    public function update($table, $data, $conditions) {
        $con = $this->openDB();
        $set = [];
        $params = [];
        $types = "";
        
        foreach ($data as $key => $value) {
            $set[] = "$key = ?";
            $params[] = $value;
            $types .= is_int($value) ? "i" : "s";
        }
        
        $where = [];
        foreach ($conditions as $key => $value) {
            $where[] = "$key = ?";
            $params[] = $value;
            $types .= is_int($value) ? "i" : "s";
        }
        
        $sql = "UPDATE $table SET " . implode(", ", $set) . " WHERE " . implode(" AND ", $where);
        return $this->executeQuery($sql, $params, $types) !== false;
    }

    /**
     * Xóa dữ liệu
     */
    public function delete($table, $conditions) {
        $con = $this->openDB();
        $where = [];
        $params = [];
        $types = "";
        
        foreach ($conditions as $key => $value) {
            $where[] = "$key = ?";
            $params[] = $value;
            $types .= is_int($value) ? "i" : "s";
        }
        
        $sql = "DELETE FROM $table WHERE " . implode(" AND ", $where);
        return $this->executeQuery($sql, $params, $types) !== false;
    }
}

// require_once __DIR__ .'/mConnect.php';
//     class ModelSQL extends connect{
//         public function UpSQL($sql){       
//             $con = $this->OpenDB(); 
//             $result = mysqli_query($con,$sql);
//             $this -> closeDB();
//             return $result;
//         }
//         public function ViewData($table, $condition = []) {
//             $con = $this->OpenDB();
//             if (!empty($condition)) {
//                 $keys = array_keys($condition);
//                 $placeholders = implode(" AND ", array_map(fn($key) => "$key = ?", $keys));
//                 $sql = "SELECT * FROM $table WHERE $placeholders";
//                 $stmt = $con->prepare($sql);
//                 $stmt->bind_param(str_repeat("s", count($condition)), ...array_values($condition));
//             } else {
//                 $sql = "SELECT * FROM $table";
//                 $stmt = $con->prepare($sql);
//             }
//             $stmt->execute();
//             $result = $stmt->get_result();
//             $this->closeDB();
//             return $result;
//         }
        
//         // public function ViewData($table, $condition=array()){
//         //     $whereClause ="";
//         //     $wherePart = array();
//         //     if(!empty($condition)){
//         //         $p = new connect;
//         //         $con= $p->OpenDB();
//         //         foreach ($condition as $key => $value) {
//         //             $wherePart[] = "$key = '" . mysqli_real_escape_string($con, $value) . "'";
//         //         }
//         //         $p->closeDB($con);
//         //     }
//         //     $whereClause = "WHERE " . implode(" AND ", $wherePart);
//         //     if(!empty($condition)){
//         //         $sql = "SELECT * FROM $table $whereClause";
//         //     }else{
//         //         $sql = "SELECT * FROM $table";
//         //     }
//         //     return $this->UpSQL($sql);
//         //     // return $sql; 
//         // }
//         // public function Insert($table, $data) {
//         //     $columns = implode(",", array_keys($data));
//         //     $values = implode("','", array_values($data));
//         //     $sql = "INSERT INTO $table ($columns) VALUES ('$values')";
//         //     return $this->UpSQL($sql);
//         // }
//         public function Insert($table, $data) {
//             $con = $this->OpenDB();
//             $columns = implode(",", array_keys($data));
//             $placeholders = implode(",", array_fill(0, count($data), "?"));
//             $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)
//                     ON DUPLICATE KEY UPDATE " . implode(", ", array_map(fn($key) => "$key=VALUES($key)", array_keys($data)));
//             $stmt = $con->prepare($sql);
//             $stmt->bind_param(str_repeat("s", count($data)), ...array_values($data));
//             $result = $stmt->execute();
//             $this->closeDB();
//             return $result;
//         }
//         // public function Update($table, $data, $condition) {
//         //     $setValues = array();
//         //     foreach ($data as $key => $value) {
//         //         $setValues[] = "$key = '$value'";
//         //     }
//         //     $setClause = implode(", ", $setValues);
//         //     $sql = "UPDATE $table SET $setClause WHERE $condition";
//         //     return $this->UpSQL($sql);
//         // }
//         public function Update($table, $data, $condition) {
//             $con = $this->OpenDB();
//             $setValues = array();
//             foreach ($data as $key => $value) {
//                 $setValues[] = "$key = ?";
//             }
//             $sql = "UPDATE $table SET " . implode(", ", $setValues) . " WHERE $condition";
//             $stmt = $con->prepare($sql);
//             $stmt->bind_param(str_repeat("s", count($data)), ...array_values($data));
//             $result = $stmt->execute();
//             $this->closeDB();
//             return $result;
//         }
    
//         public function Delete($table, $condition) {
//             $sql = "DELETE FROM $table WHERE $condition";
//             return $this->UpSQL($sql);
//         }
//     }
?>