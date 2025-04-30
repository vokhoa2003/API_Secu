<?php
require_once __DIR__ .'/mConnect.php';
    class ModelSQL extends connect{
        public function UpSQL($sql){       
            $con = $this->OpenDB(); 
            $result = mysqli_query($con,$sql);
            $this -> closeDB();
            return $result;
        }
        public function ViewData($table, $condition = []) {
            $con = $this->OpenDB();
            if (!empty($condition)) {
                $keys = array_keys($condition);
                $placeholders = implode(" AND ", array_map(fn($key) => "$key = ?", $keys));
                $sql = "SELECT * FROM $table WHERE $placeholders";
                $stmt = $con->prepare($sql);
                $stmt->bind_param(str_repeat("s", count($condition)), ...array_values($condition));
            } else {
                $sql = "SELECT * FROM $table";
                $stmt = $con->prepare($sql);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $this->closeDB();
            return $result;
        }
        
        // public function ViewData($table, $condition=array()){
        //     $whereClause ="";
        //     $wherePart = array();
        //     if(!empty($condition)){
        //         $p = new connect;
        //         $con= $p->OpenDB();
        //         foreach ($condition as $key => $value) {
        //             $wherePart[] = "$key = '" . mysqli_real_escape_string($con, $value) . "'";
        //         }
        //         $p->closeDB($con);
        //     }
        //     $whereClause = "WHERE " . implode(" AND ", $wherePart);
        //     if(!empty($condition)){
        //         $sql = "SELECT * FROM $table $whereClause";
        //     }else{
        //         $sql = "SELECT * FROM $table";
        //     }
        //     return $this->UpSQL($sql);
        //     // return $sql; 
        // }
        // public function Insert($table, $data) {
        //     $columns = implode(",", array_keys($data));
        //     $values = implode("','", array_values($data));
        //     $sql = "INSERT INTO $table ($columns) VALUES ('$values')";
        //     return $this->UpSQL($sql);
        // }
        public function Insert($table, $data) {
            $con = $this->OpenDB();
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
        // public function Update($table, $data, $condition) {
        //     $setValues = array();
        //     foreach ($data as $key => $value) {
        //         $setValues[] = "$key = '$value'";
        //     }
        //     $setClause = implode(", ", $setValues);
        //     $sql = "UPDATE $table SET $setClause WHERE $condition";
        //     return $this->UpSQL($sql);
        // }
        public function Update($table, $data, $condition) {
            $con = $this->OpenDB();
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