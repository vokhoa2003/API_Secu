<?php
require_once __DIR__ . '/mConnect.php';

class ModelSQL extends connect {
    public function UpSQL($sql) {       
        $con = $this->OpenDB(); 
        if (!$con) return false;
        $result = mysqli_query($con, $sql);
        $this->closeDB();
        return $result;
    }

    public function ViewData($table, $condition = []) {
        $con = $this->OpenDB();
        if (!$con) return false;
        try {
            if (!empty($condition)) {
                $keys = array_keys($condition);
                $placeholders = implode(" AND ", array_map(fn($key) => "$key = ?", $keys));
                $sql = "SELECT * FROM $table WHERE $placeholders";
                $stmt = $con->prepare($sql);
                if ($stmt === false) {
                    throw new Exception("Prepare failed: " . $con->error);
                }
                $stmt->bind_param(str_repeat("s", count($condition)), ...array_values($condition));
            } else {
                $sql = "SELECT * FROM $table";
                $stmt = $con->prepare($sql);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $this->closeDB();
            return $result; // Trả về mysqli_result thay vì fetch_all
        } catch (Exception $e) {
            error_log("SQL Error in ViewData: " . $e->getMessage());
            $this->closeDB();
            return false;
        }
    }

    public function Insert($table, $data) {
        $con = $this->OpenDB();
        if (!$con) return false;
        $columns = implode(",", array_keys($data));
        $placeholders = implode(",", array_fill(0, count($data), "?"));
        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)
                ON DUPLICATE KEY UPDATE " . implode(", ", array_map(fn($key) => "$key=VALUES($key)", array_keys($data)));
        $stmt = $con->prepare($sql);
        $stmt->bind_param(str_repeat("s", count($data)), ...array_values($data));
        $result = $stmt->execute();
        $this->closeDB();
        return $result;
    }

    public function Update($table, $data, $condition) {
        $con = $this->OpenDB();
        if (!$con) return false;
        $setValues = array();
        foreach ($data as $key => $value) {
            $setValues[] = "$key = ?";
        }
        $sql = "UPDATE $table SET " . implode(", ", $setValues) . " WHERE $condition";
        $stmt = $con->prepare($sql);
        $stmt->bind_param(str_repeat("s", count($data)), ...array_values($data));
        $result = $stmt->execute();
        $this->closeDB();
        return $result;
    }

    public function Delete($table, $condition) {
        $sql = "DELETE FROM $table WHERE $condition";
        return $this->UpSQL($sql);
    }
}
?>