<?php
require_once __DIR__ . '/../Model/mSQL.php';
class DataController{
    public function GetUserData($google_id = null , $email = null , $full_name = null, $role = null) {
        $p = new ModelSQL;
        $conditions =array();
        //if ($id) $conditions['id'] = $id;
        if ($google_id) $conditions['GoogleID'] = $google_id;
        if ($email) $conditions['email'] = $email;
        if ($full_name) $conditions['FullName'] = $full_name;
        if ($role) $conditions['role'] = $role;
        return $p->ViewData("account", $conditions);
    }
    public function AddUser($google_id, $email, $full_name, $role = 'customer') {
        $p = new ModelSQL;
        $data = array(
            'GoogleID' => $google_id,
            'email' => $email,
            'FullName' => $full_name,
            'role' => $role,
            'CreateDate' => date('Y-m-d H:i:s'),
            'UpdateDate' => date('Y-m-d H:i:s')
        );
        return $p->Insert("account", $data);
    }
    public function UpdateUser($id, $google_id, $email, $full_name) {
        $p = new ModelSQL;
        $p_connect = new connect;
        $con = $p_connect->OpenDB();
        $stmt = $con->prepare("SELECT * FROM account WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $p_connect->closeDB();
        if (mysqli_num_rows($result) == 0) {
            return false;
        }
        $oldData = mysqli_fetch_assoc($result);
        $google_id = !empty($google_id) ? $google_id : $oldData['google_id'];
        $email = !empty($email) ? $email : $oldData['email'];
        $full_name = !empty($full_name) ? $full_name : $oldData['full_name'];
        $data = array(
            'GoogleID' => $google_id,
            'email' => $email,
            'FullName' => $full_name,
            'UpdateDate' => date('Y-m-d H:i:s')
        );
        return $p->Update("account", $data, "id='$id'");
    }
    public function DeleteUser($id) {
        $p = new ModelSQL;
        $p_connect = new connect;
        $con = $p_connect->OpenDB();
        $stmt = $con->prepare("DELETE FROM account WHERE id = ?");
        $stmt->bind_param("i", $id);
        $result = $stmt->execute();
        $p_connect->closeDB();
        return $result;
    }
}
?>