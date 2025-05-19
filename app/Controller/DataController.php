<?php
require_once __DIR__ . '/../Model/mSQL.php';

class DataController {
    private $modelSQL;

    public function __construct() {
        $this->modelSQL = new ModelSQL();
    }

    /**
     * Lấy dữ liệu từ bảng với điều kiện
     */
    public function getData($table, $conditions = [], $columns = ['*'], $orderBy = '', $limit = '') {
        $result = $this->modelSQL->viewData($table, $conditions, $columns, $orderBy, $limit);
        $data = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }
        return $data;
    }

    /**
     * Thêm dữ liệu vào bảng
     */
    public function addData($table, $data) {
        $data['CreateDate'] = date('Y-m-d H:i:s');
        $data['UpdateDate'] = date('Y-m-d H:i:s');
        return $this->modelSQL->insert($table, $data);
    }

    /**
     * Cập nhật dữ liệu
     */
    public function updateData($table, $data, $conditions) {
        $oldDataResult = $this->modelSQL->viewData($table, $conditions);
        if (!$oldDataResult || $oldDataResult->num_rows === 0) {
            return false; // Không tìm thấy dữ liệu để cập nhật
        }
        $oldData = $oldDataResult->fetch_assoc();

        // Duyệt qua các field cần cập nhật
        foreach ($data as $key => $value) {
            // Nếu giá trị mới null hoặc chuỗi rỗng, giữ lại giá trị cũ
            if ($value === null || $value === '') {
                if (array_key_exists($key, $oldData)) {
                    $data[$key] = $oldData[$key];
                } else {
                    unset($data[$key]); // Không tồn tại trong DB, bỏ qua
                }
            }
        }

        // Cập nhật thời gian
        $data['UpdateDate'] = date('Y-m-d H:i:s');

        return $this->modelSQL->update($table, $data, $conditions);
        }

        /**
         * Xóa dữ liệu
         */
        public function deleteData($table, $conditions) {
            return $this->modelSQL->delete($table, $conditions);
    }
}
// require_once __DIR__ . '/../Model/mSQL.php';
// class DataController{
//     public function GetUserData($google_id = null , $email = null , $full_name = null, $role = null) {
//         $p = new ModelSQL;
//         $conditions =array();
//         //if ($id) $conditions['id'] = $id;
//         if ($google_id) $conditions['GoogleID'] = $google_id;
//         if ($email) $conditions['email'] = $email;
//         if ($full_name) $conditions['FullName'] = $full_name;
//         if ($role) $conditions['role'] = $role;
//         return $p->ViewData("account", $conditions);
//     }
//     public function AddUser($google_id, $email, $full_name, $role = 'customer') {
//         $p = new ModelSQL;
//         $data = array(
//             'GoogleID' => $google_id,
//             'email' => $email,
//             'FullName' => $full_name,
//             'role' => $role,
//             'CreateDate' => date('Y-m-d H:i:s'),
//             'UpdateDate' => date('Y-m-d H:i:s')
//         );
//         return $p->Insert("account", $data);
//     }
//     public function UpdateUser($google_id, $email, $full_name, $phone, $address,$birthdate,$identitynumber) {
//         $p = new ModelSQL;
//         $p_connect = new connect;
//         $con = $p_connect->OpenDB();
//         $stmt = $con->prepare("SELECT * FROM account WHERE GoogleID = ?");
//         $stmt->bind_param("i", $google_id);
//         $stmt->execute();
//         $result = $stmt->get_result();
//         $p_connect->closeDB();
//         if (mysqli_num_rows($result) == 0) {
//             return false;
//         }
//         $oldData = mysqli_fetch_assoc($result);
//         $google_id = !empty($google_id) ? $google_id : $oldData['GoogleID'];
//         $email = !empty($email) ? $email : $oldData['email'];
//         $full_name = !empty($full_name) ? $full_name : $oldData['FullName'];
//         $birthdate = !empty($birthdate) ? $birthdate : $oldData['BirthDate'];
//         $phone = !empty($phone) ? $phone : $oldData['Phone'];
//         $address = !empty($address) ? $address : $oldData['Address'];
//         $identitynumber = !empty($identitynumber) ? $identitynumber : $oldData['IdentityNumber'];
//         $data = array(
//             'email' => $email,
//             'FullName' => $full_name,
//             'Phone' => $phone,
//             'Address' => $address,
//             'BirthDate' => $birthdate,
//             'IdentityNumber' => $identitynumber,
//             'UpdateDate' => date('Y-m-d H:i:s')
//         );
//         return $p->Update("account", $data, "GoogleID='$google_id'");
//     }
//     public function DeleteUser($id) {
//         $p = new ModelSQL;
//         $p_connect = new connect;
//         $con = $p_connect->OpenDB();
//         $stmt = $con->prepare("DELETE FROM account WHERE id = ?");
//         $stmt->bind_param("i", $id);
//         $result = $stmt->execute();
//         $p_connect->closeDB();
//         return $result;
//     }
// }
?>