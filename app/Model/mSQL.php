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
    // Helper: lấy danh sách cột thực tế của bảng (trả array các tên cột)
    private function getTableColumns(string $table): array {
        $con = $this->openDB();
        $cols = [];
        try {
            $res = $con->query("SHOW COLUMNS FROM `$table`");
            if ($res instanceof mysqli_result) {
                while ($r = $res->fetch_assoc()) {
                    $cols[] = $r['Field'];
                }
                $res->free();
            }
        } catch (\Throwable $e) {
            @file_put_contents(__DIR__.'/../../logs/mssql_errors.log', date('c')." SHOW COLUMNS error: ".$e->getMessage()."\n", FILE_APPEND);
        }
        return $cols;
    }

    // Thực thi truy vấn chuẩn hoá, bắt exception và log (tránh in HTML fatal)
    public function executeQuery($sql, $params = [], $types = "") {
        $con = $this->openDB();
        try {
            $stmt = $con->prepare($sql);
            if ($stmt === false) {
                @file_put_contents(__DIR__.'/../../logs/mssql_errors.log', date('c')." Prepare failed: ".$con->error."\nSQL: ".$sql."\nParams: ".json_encode($params)."\n", FILE_APPEND);
                return false;
            }

            if (!empty($params)) {
                if (empty($types)) {
                    $types = str_repeat("s", count($params));
                }
                // build bind params by reference
                $bindParams = [];
                $bindParams[] = & $types;
                // numeric keys preserved order
                $i = 0;
                foreach ($params as $k => $v) {
                    $bindParams[] = & $params[$k];
                    $i++;
                }
                call_user_func_array([$stmt, 'bind_param'], $bindParams);
            }

            if (!$stmt->execute()) {
                @file_put_contents(__DIR__.'/../../logs/mssql_errors.log', date('c')." Execute failed: ".$stmt->error."\nSQL: ".$sql."\nParams: ".json_encode($params)."\n", FILE_APPEND);
                $stmt->close();
                return false;
            }

            if (stripos(trim($sql), 'select') === 0) {
                $result = $stmt->get_result();
            } else {
                $result = $stmt->affected_rows;
            }

            $stmt->close();
            return $result;
        } catch (\mysqli_sql_exception $me) {
            @file_put_contents(__DIR__.'/../../logs/mssql_errors.log', date('c')." SQL EXCEPTION: ".$me->getMessage()."\nSQL: ".$sql."\nParams: ".json_encode($params)."\n", FILE_APPEND);
            return false;
        } catch (\Throwable $e) {
            @file_put_contents(__DIR__.'/../../logs/mssql_errors.log', date('c')." ERROR: ".$e->getMessage()."\nSQL: ".$sql."\nParams: ".json_encode($params)."\n", FILE_APPEND);
            return false;
        }
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
    // Insert: giữ nguyên tên keys, lọc theo cột thật của bảng để tránh Unknown column
    public function insert($table, $data, $onDuplicateKeyUpdate = true) {
        $con = $this->openDB();

        if (empty($data) || empty($table)) return false;

        // Lấy cột thật của bảng, lọc data
        $allowedCols = $this->getTableColumns($table);
        if (empty($allowedCols)) {
            @file_put_contents(__DIR__.'/../../logs/mssql_errors.log', date('c')." insert: table not found or no columns for $table\n", FILE_APPEND);
            return false;
        }

        $filtered = [];
        foreach ($data as $k => $v) {
            // chấp nhận key trùng hoặc khác case: dùng exact match hoặc case-insensitive
            if (in_array($k, $allowedCols, true)) {
                $filtered[$k] = $v;
            } else {
                // thử match case-insensitive
                foreach ($allowedCols as $col) {
                    if (strcasecmp($col, $k) === 0) { $filtered[$col] = $v; break; }
                }
            }
        }

        if (empty($filtered)) {
            @file_put_contents(__DIR__.'/../../logs/mssql_errors.log', date('c')." insert: no valid columns after filtering for table $table. payload: ".json_encode($data)."\n", FILE_APPEND);
            return false;
        }

        $columns = array_keys($filtered);
        $placeholders = array_fill(0, count($filtered), "?");
        $sql = "INSERT INTO `$table` (`" . implode("`,`", $columns) . "`) VALUES (" . implode(", ", $placeholders) . ")";
        if ($onDuplicateKeyUpdate) {
            $updates = array_map(fn($key) => "`$key`=VALUES(`$key`)", $columns);
            $sql .= " ON DUPLICATE KEY UPDATE " . implode(", ", $updates);
        }

        // types
        $types = "";
        $values = array_values($filtered);
        foreach ($values as $val) {
            if (is_int($val)) $types .= "i";
            elseif (is_float($val)) $types .= "d";
            else $types .= "s";
        }

        $res = $this->executeQuery($sql, $values, $types);
        return $res !== false;
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
    public function autoQuery($tables, $columns = ['*'], $join = [], $conditions = [], $groupBy = '', $orderBy = '') {
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
            // foreach ($conditions as $field => $value) {
            //     $conds[] = "$field = ?";
            //     $params[] = $value;
            //     $types .= is_int($value) ? 'i' : 's';
            // }
            foreach ($conditions as $field => $value) {
                if (is_array($value)) {
                    $placeholders = implode(',', array_fill(0, count($value), '?'));
                    $conds[] = "$field IN ($placeholders)";
                    foreach ($value as $v) {
                        $params[] = $v;
                        $types .= is_int($v) ? 'i' : 's';
                    }
                } else {
                    $conds[] = "$field = ?";
                    $params[] = $value;
                    $types .= is_int($value) ? 'i' : 's';
                }
            }
            $sql .= implode(" AND ", $conds);
        }

        // 5️⃣ Xử lý GROUP BY nếu có (hỗ trợ string hoặc array)
        if (!empty($groupBy)) {
            if (is_array($groupBy)) {
                $sql .= " GROUP BY " . implode(", ", $groupBy);
            } else {
                $sql .= " GROUP BY " . $groupBy;
            }
        }
        if (!empty($orderBy)) {
            $sql .= " ORDER BY " . $orderBy;
        }

        // 6️⃣ Debug in ra câu SQL (tùy chọn)
        // error_log("AUTOQUERY SQL: " . $sql);

        // 7️⃣ Thực thi truy vấn
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

    // Thêm method multiInsert để chèn nhiều hàng (các operations) và trả về insert ids
    public function multiInsert($operations) {
        $con = $this->openDB();
        $con->begin_transaction();
        try {
            $allResults = [];
            foreach ($operations as $op) {
                $table = $op['table'] ?? '';
                $rows = $op['rows'] ?? [];
                if (!$table || empty($rows)) {
                    $allResults[] = ['status' => 'error', 'message' => 'Missing table or rows'];
                    continue;
                }
                $opInsertIds = [];
                foreach ($rows as $row) {
                    $columns = array_keys($row);
                    $placeholders = implode(", ", array_fill(0, count($columns), "?"));
                    $colsSql = implode("`, `", $columns);
                    $sql = "INSERT INTO `$table` (`$colsSql`) VALUES ($placeholders)";
                    $stmt = $con->prepare($sql);
                    if ($stmt === false) {
                        throw new Exception("Prepare failed: " . $con->error);
                    }

                    // build types and params
                    $types = "";
                    $params = [];
                    foreach ($columns as $c) {
                        $val = $row[$c];
                        $params[] = $val;
                        if (is_int($val)) $types .= "i";
                        elseif (is_float($val)) $types .= "d";
                        else $types .= "s";
                    }

                    // bind params dynamically (by reference)
                    $bindParams = [];
                    $bindParams[] = & $types;
                    for ($i = 0; $i < count($params); $i++) {
                        $bindParams[] = & $params[$i];
                    }
                    call_user_func_array(array($stmt, 'bind_param'), $bindParams);

                    if (!$stmt->execute()) {
                        throw new Exception("Execute failed: " . $stmt->error);
                    }
                    $opInsertIds[] = $con->insert_id;
                    $stmt->close();
                }
                $allResults[] = ['status' => 'success', 'insert_ids' => $opInsertIds];
            }
            $con->commit();
            return ['status' => 'success', 'data' => $allResults];
        } catch (Exception $e) {
            $con->rollback();
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
