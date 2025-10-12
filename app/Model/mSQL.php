<?php
require_once __DIR__ . '/mConnect.php';

class ModelSQL extends Connect {
    /**
     * Thực thi truy vấn SQL tùy chỉnh
     */
    // public function executeQuery($sql, $params = [], $types = "") {
    //     $con = $this->openDB();
    //     $stmt = $con->prepare($sql);
    //     if (!empty($params)) {
    //         $stmt->bind_param($types ?: str_repeat("s", count($params)), ...$params);
    //     }
    //     $stmt->execute();
    //     $result = $stmt->get_result() ?: $stmt->affected_rows;
    //     $stmt->close();
    //     return $result;
    // }
    public function executeQuery($sql, $params = [], $types = "") {
        $con = $this->openDB();
        $stmt = $con->prepare($sql);
        if (!empty($params)) {
            if (empty($types)) {
                $types = str_repeat("s", count($params));
            }

            // Tạo mảng tham chiếu
            $bindParams = [];
            $bindParams[] = &$types;
            foreach ($params as $key => $value) {
                $bindParams[] = &$params[$key]; // tham chiếu
            }

            // Gọi bind_param bằng call_user_func_array
            call_user_func_array([$stmt, 'bind_param'], $bindParams);
        }

        $stmt->execute();
        //$result = $stmt->get_result() ?: $stmt->affected_rows;
        if (stripos(trim($sql), 'select') === 0) {
            // Nếu là SELECT thì phải dùng get_result()
            $result = $stmt->get_result();
        } else {
            // Các truy vấn khác như INSERT/UPDATE/DELETE
            $result = $stmt->affected_rows;
        }

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

    /**
     * Tự động tạo truy vấn SELECT với JOIN và điều kiện
     */
    // public function autoQuery($tables, $columns = ['*'], $join = [], $conditions = []) {
    //     $sql = "SELECT " . implode(", ", $columns) . " FROM ";
    //     $params = [];
    //     $types = '';

    //     if (is_array($tables)) {
    //         $sql .= $tables[0];
    //         if (!empty($join) && isset($join['type'], $join['on'], $tables[1])) {
    //             $sql .= " {$join['type']} JOIN {$tables[1]} ON " . implode(" AND ", $join['on']);
    //         }
    //     } else {
    //         $sql .= $tables;
    //     }

    //     if (!empty($conditions)) {
    //         $sql .= " WHERE ";
    //         $conds = [];
    //         foreach ($conditions as $field => $value) {
    //             $conds[] = "$field = ?";
    //             $params[] = $value;
    //             $types .= is_int($value) ? 'i' : 's';
    //         }
    //         $sql .= implode(" AND ", $conds);
    //     }

    //     return $this->executeQuery($sql, $params, $types);
    // }
    public function autoQuery($tables, $columns = ['*'], $join = [], $conditions = []) {
        // 1️⃣ Bắt đầu câu SQL cơ bản
        $sql = "SELECT " . implode(", ", $columns) . " FROM ";

        $params = [];
        $types = '';

        // 2️⃣ Xử lý bảng chính
        if (is_array($tables) && count($tables) > 0) {
            $sql .= $tables[0];

            // 3️⃣ Duyệt từng phần tử join
            if (!empty($join) && is_array($join)) {
                for ($i = 0; $i < count($join); $i++) {
                    if (!isset($tables[$i + 1])) break; // tránh lỗi nếu thiếu bảng
                    $joinType = strtoupper($join[$i]['type'] ?? 'INNER');
                    $joinOn = $join[$i]['on'] ?? [];
                    if (!empty($joinOn)) {
                        $sql .= " {$joinType} JOIN {$tables[$i + 1]} ON " . implode(" AND ", $joinOn);
                    }
                }
            }
        } else {
            $sql .= $tables;
        }

        // 4️⃣ Xử lý điều kiện WHERE
        if (!empty($conditions)) {
            $sql .= " WHERE ";
            $conds = [];
            foreach ($conditions as $field => $value) {
                $conds[] = "$field = ?";
                $params[] = $value;
                $types .= is_int($value) ? 'i' : 's';
            }
            $sql .= implode(" AND ", $conds);
        }

        // 5️⃣ Debug in ra câu SQL (tùy chọn)
        // error_log("AUTOQUERY SQL: " . $sql);

        // 6️⃣ Thực thi truy vấn
        return $this->executeQuery($sql, $params, $types);
    }

    public function autoUpdate($table, $data = [], $method = 'UPSERT') {
        if (empty($table) || empty($data)) {
            return ['status' => 'error', 'message' => 'Thiếu dữ liệu table hoặc data'];
        }

        try {
            foreach ($data as $row) {
                $columns = [];
                $placeholders = [];
                $updates = [];
                $params = [];
                $types = '';

                foreach ($row as $key => $val) {
                    $col = strpos($key, '.') !== false ? explode('.', $key)[1] : $key;
                    $columns[] = "`$col`";
                    $placeholders[] = '?';
                    $updates[] = "`$col` = VALUES(`$col`)";
                    $params[] = $val;
                    $types .= is_int($val) ? 'i' : 's';
                }

                if (strtoupper($method) === 'UPSERT') {
                    $sql = "INSERT INTO `$table` (" . implode(", ", $columns) . ")
                            VALUES (" . implode(", ", $placeholders) . ")
                            ON DUPLICATE KEY UPDATE " . implode(", ", $updates);
                } elseif (strtoupper($method) === 'INSERT') {
                    $sql = "INSERT INTO `$table` (" . implode(", ", $columns) . ")
                            VALUES (" . implode(", ", $placeholders) . ")";
                } else { // UPDATE
                    if (!isset($row['questions.id'])) {
                        throw new Exception("Thiếu khóa chính questions.id để UPDATE");
                    }
                    $whereCol = "QuestionId";
                    $sql = "UPDATE `$table` SET " . implode(", ", array_map(fn($c) => "$c = ?", $columns)) . " 
                            WHERE `$whereCol` = ?";
                    $params[] = $row['questions.id'];
                    $types .= is_int($row['questions.id']) ? 'i' : 's';
                }

                $this->executeQuery($sql, $params, $types);
            }

            return ['status' => 'success', 'message' => 'Cập nhật thành công'];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

}

    /**
     * Tự động chèn một hoặc nhiều hàng vào một bảng bằng cách sử dụng một giao dịch.
     * @param string $table Tên bảng.
     * @param array $data Dữ liệu để chèn. Có thể là một mảng kết hợp cho một hàng hoặc một mảng của các mảng kết hợp cho nhiều hàng.
     * @return bool Trả về true nếu thành công, false nếu thất bại.
     */
//     public function autoInsert($table, $data) {
//         if (empty($data)) {
//             return false;
//         }
//         // Nếu dữ liệu là một hàng đơn, hãy đặt nó vào một mảng để xử lý nhất quán
//         if (!is_array(reset($data))) {
//             $data = [$data];
//         }

//         $con = $this->openDB();
//         $con->begin_transaction();

//         try {
//             // Lấy các cột từ hàng đầu tiên
//             $columns = array_keys($data[0]);
//             $columnSql = implode('`, `', $columns);
//             $placeholderSql = implode(', ', array_fill(0, count($columns), '?'));
//             $sql = "INSERT INTO `$table` (`$columnSql`) VALUES ($placeholderSql)";
//             $stmt = $con->prepare($sql);

//             // Xác định các loại tham số từ hàng đầu tiên
//             $types = '';
//             foreach ($data[0] as $value) {
//                 if (is_int($value)) $types .= 'i';
//                 elseif (is_float($value)) $types .= 'd';
//                 else $types .= 's';
//             }

//             foreach ($data as $row) {
//                 if (count($row) != count($columns)) {
//                     throw new Exception("Số lượng cột không khớp.");
//                 }
//                 $stmt->bind_param($types, ...array_values($row));
//                 $stmt->execute();
//             }

//             $con->commit();
//             return true;
//         } catch (Exception $e) {
//             $con->rollback();
//             // Ghi lại lỗi nếu cần
//             error_log('autoInsert failed: ' . $e->getMessage());
//             return false;
//         }
//     }

//     /**
//      * Tự động cập nhật các hàng trong một bảng dựa trên các điều kiện.
//      * @param string $table Tên bảng.
//      * @param array $data Một mảng kết hợp của dữ liệu để cập nhật (cột => giá trị).
//      * @param array $conditions Một mảng kết hợp của các điều kiện cho mệnh đề WHERE (cột => giá trị).
//      * @return bool Trả về true nếu thành công, false nếu thất bại.
//      */
//     public function autoUpdate($table, $data, $conditions) {
//         if (empty($data) || empty($conditions)) {
//             return false;
//         }

//         $params = [];
//         $types = '';

//         $setClauses = [];
//         foreach ($data as $key => $value) {
//             $setClauses[] = "`$key` = ?";
//             $params[] = $value;
//             if (is_int($value)) $types .= 'i';
//             elseif (is_float($value)) $types .= 'd';
//             else $types .= 's';
//         }
//         $sql = "UPDATE `$table` SET " . implode(', ', $setClauses);

//         $whereClauses = [];
//         foreach ($conditions as $key => $value) {
//             $whereClauses[] = "`$key` = ?";
//             $params[] = $value;
//             if (is_int($value)) $types .= 'i';
//             elseif (is_float($value)) $types .= 'd';
//             else $types .= 's';
//         }
//         $sql .= " WHERE " . implode(' AND ', $whereClauses);

//         return $this->executeQuery($sql, $params, $types) !== false;
//     }

//     /**
//      * Tự động xóa các hàng khỏi một bảng dựa trên các điều kiện.
//      * @param string $table Tên bảng.
//      * @param array $conditions Một mảng kết hợp của các điều kiện cho mệnh đề WHERE (cột => giá trị).
//      * @return bool Trả về true nếu thành công, false nếu thất bại.
//      */
//     public function autoDelete($table, $conditions) {
//         if (empty($conditions)) {
//             return false; // Ngăn chặn việc vô tình xóa tất cả các hàng
//         }

//         $params = [];
//         $types = '';
//         $whereClauses = [];
//         foreach ($conditions as $key => $value) {
//             $whereClauses[] = "`$key` = ?";
//             $params[] = $value;
//             if (is_int($value)) $types .= 'i';
//             elseif (is_float($value)) $types .= 'd';
//             else $types .= 's';
//         }
//         $sql = "DELETE FROM `$table` WHERE " . implode(' AND ', $whereClauses);

//         return $this->executeQuery($sql, $params, $types) !== false;
//     }
// }

// // require_once __DIR__ .'/mConnect.php';
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
