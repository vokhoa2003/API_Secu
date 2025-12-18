<?php
require_once __DIR__ . '/mConnect.php';

class ModelSQL extends Connect
{

    // Helper: lấy danh sách cột thực tế của bảng (trả array các tên cột)
    private function getTableColumns(string $table): array
    {
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
            @file_put_contents(__DIR__ . '/../../logs/mssql_errors.log', date('c') . " SHOW COLUMNS error: " . $e->getMessage() . "\n", FILE_APPEND);
        }
        return $cols;
    }

    // Thực thi truy vấn chuẩn hoá, bắt exception và log (tránh in HTML fatal)
    public function executeQuery($sql, $params = [], $types = "")
    {
        $con = $this->openDB();
        try {
            $stmt = $con->prepare($sql);
            // SQL không chứa dữ liệu người dùng → không thể Inject
            // MySQL parse SQL trước, không bị phá vỡ cú pháp
            if ($stmt === false) {
                @file_put_contents(__DIR__ . '/../../logs/mssql_errors.log', date('c') . " Prepare failed: " . $con->error . "\nSQL: " . $sql . "\nParams: " . json_encode($params) . "\n", FILE_APPEND);
                return false;
            }

            if (!empty($params)) {
                if (empty($types)) {
                    $types = str_repeat("s", count($params));
                }
                // build bind params by reference
                $bindParams = [];
                $bindParams[] = &$types;
                // numeric keys preserved order
                $i = 0;
                foreach ($params as $k => $v) {
                    $bindParams[] = &$params[$k];
                    $i++;
                }
                call_user_func_array([$stmt, 'bind_param'], $bindParams);
                // Giá trị người nhập vào được gửi tách biệt
                // MySQL coi như data thuần, không phải SQL
                // SQLi bị triệt tiêu vì attacker không thể chèn ' OR '1'='1 vào câu SQL
            }

            if (!$stmt->execute()) {
                @file_put_contents(__DIR__ . '/../../logs/mssql_errors.log', date('c') . " Execute failed: " . $stmt->error . "\nSQL: " . $sql . "\nParams: " . json_encode($params) . "\n", FILE_APPEND);
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
            @file_put_contents(__DIR__ . '/../../logs/mssql_errors.log', date('c') . " SQL EXCEPTION: " . $me->getMessage() . "\nSQL: " . $sql . "\nParams: " . json_encode($params) . "\n", FILE_APPEND);
            return false;
        } catch (\Throwable $e) {
            @file_put_contents(__DIR__ . '/../../logs/mssql_errors.log', date('c') . " ERROR: " . $e->getMessage() . "\nSQL: " . $sql . "\nParams: " . json_encode($params) . "\n", FILE_APPEND);
            return false;
        }
    }

    /**
     * Lấy dữ liệu từ bảng với điều kiện linh hoạt
     */
    public function viewData($table, $conditions = [], $columns = ['*'], $orderBy = '', $limit = '')
    {
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


    // Insert: giữ nguyên tên keys, lọc theo cột thật của bảng để tránh Unknown column
    public function insert($table, $data, $onDuplicateKeyUpdate = true)
    {
        $con = $this->openDB();

        if (empty($data) || empty($table)) return false;

        // Lấy cột thật của bảng, lọc data
        $allowedCols = $this->getTableColumns($table);
        // Kẻ tấn công không thể đặt key như "id; DROP TABLE users"
        // Mọi key không tồn tại trong bảng → tự loại bỏ
        if (empty($allowedCols)) {
            @file_put_contents(__DIR__ . '/../../logs/mssql_errors.log', date('c') . " insert: table not found or no columns for $table\n", FILE_APPEND);
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
                    if (strcasecmp($col, $k) === 0) {
                        $filtered[$col] = $v;
                        break;
                    }
                }
            }
        }

        if (empty($filtered)) {
            @file_put_contents(__DIR__ . '/../../logs/mssql_errors.log', date('c') . " insert: no valid columns after filtering for table $table. payload: " . json_encode($data) . "\n", FILE_APPEND);
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
    public function update($table, $data, $conditions)
    {
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
    public function delete($table, $conditions)
    {
        $con = $this->openDB();
        $where = [];
        $params = [];
        $types = "";

        foreach ($conditions as $key => $value) {
            // Nếu là mảng → dùng IN (...)
            if (is_array($value)) {
                $placeholders = implode(",", array_fill(0, count($value), "?"));
                $where[] = "$key IN ($placeholders)";

                foreach ($value as $v) {
                    $params[] = $v;
                    $types .= is_int($v) ? "i" : "s";
                }
            } else {
                // Giá trị đơn → WHERE key = ?
                $where[] = "$key = ?";
                $params[] = $value;
                $types .= is_int($value) ? "i" : "s";
            }
        }

        $sql = "DELETE FROM $table WHERE " . implode(" AND ", $where);
        return $this->executeQuery($sql, $params, $types) !== false;
    }

    public function autoQuery($tables, $columns = ['*'], $join = [], $conditions = [], $groupBy = '', $orderBy = '')
    {
        // Bắt đầu câu SQL cơ bản
        $sql = "SELECT " . implode(", ", $columns) . " FROM ";

        $params = [];
        $types = '';

        // Xử lý bảng chính
        if (is_array($tables) && count($tables) > 0) {
            $sql .= $tables[0];

            // Duyệt từng phần tử join
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

        // Xử lý điều kiện WHERE
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

        // Xử lý GROUP BY nếu có (hỗ trợ string hoặc array)
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

        // Debug in ra câu SQL (tùy chọn)
        //         error_log("AUTOQUERY SQL: " . $sql);
        // error_log("PARAMS: " . print_r($params, true));
        // error_log("TYPES: " . $types);

        // Thực thi truy vấn
        return $this->executeQuery($sql, $params, $types);
    }

    public function autoUpdate($table, $data = [], $method = 'UPSERT')
    {
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
                } elseif (strtoupper($method) === 'UPDATE_WHERE') {

                    if (!isset($row['where']) || !is_array($row['where'])) {
                        throw new Exception("Thiếu điều kiện WHERE: cần truyền vào key '_where' dạng mảng");
                    }

                    $setParts = [];
                    $whereParts = [];
                    $params = [];
                    $types = '';

                    // ----- SET PART -----
                    foreach ($row as $key => $val) {
                        if ($key === 'where') continue;

                        $col = strpos($key, '.') !== false ? explode('.', $key)[1] : $key;
                        $setParts[] = "`$col` = ?";
                        $params[] = $val;
                        $types .= is_int($val) ? 'i' : 's';
                    }

                    // ----- WHERE PART -----
                    foreach ($row['where'] as $key => $val) {
                        $col = strpos($key, '.') !== false ? explode('.', $key)[1] : $key;
                        $whereParts[] = "$col = ?";
                        $params[] = $val;
                        $types .= is_int($val) ? 'i' : 's';
                    }

                    // ----- FINAL SQL -----
                    $sql = "UPDATE `$table` SET " . implode(", ", $setParts) .
                        " WHERE " . implode(" AND ", $whereParts);
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
    public function multiInsert($operations)
    {
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
                    // Lọc các key theo cột thực tế của bảng để tránh lỗi Unknown column
                    $allowedCols = $this->getTableColumns($table);
                    $filteredRow = [];
                    foreach ($row as $k => $v) {
                        if (in_array($k, $allowedCols, true)) $filteredRow[$k] = $v;
                        else {
                            foreach ($allowedCols as $col) if (strcasecmp($col, $k) === 0) {
                                $filteredRow[$col] = $v;
                                break;
                            }
                        }
                    }
                    if (empty($filteredRow)) {
                        throw new Exception("No valid columns for table $table in row payload");
                    }
                    $columns = array_keys($filteredRow);
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
                        $val = $filteredRow[$c];
                        $params[] = $val;
                        if (is_int($val)) $types .= "i";
                        elseif (is_float($val)) $types .= "d";
                        else $types .= "s";
                    }

                    // bind params dynamically (by reference)
                    $bindParams = [];
                    $bindParams[] = &$types;
                    for ($i = 0; $i < count($params); $i++) {
                        $bindParams[] = &$params[$i];
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
