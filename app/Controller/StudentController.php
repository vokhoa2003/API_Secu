<?php
    // include("../app/Model/mSQL.php");
    // class StudentController{
    //     public function Get($masv = null, $hodem = null, $ten = null, $lop = null){
    //         $p = new ModelSQL;
    //         $conditions =array();
    //         if ($masv) $conditions['masv'] = $masv;
    //         if ($hodem) $conditions['hodem'] = $hodem;
    //         if ($ten) $conditions['ten'] = $ten;
    //         if ($lop) $conditions['lop'] = $lop;
    //         return $p->ViewData("sinhvien01", $conditions);
    //         // return $conditions;
    //     }
    //     public function AddStudent($masv, $hodem, $ten, $lop) {
    //         $p = new ModelSQL;
    //         $data = array(
    //             'masv' => $masv,
    //             'hodem' => $hodem,
    //             'ten' => $ten,
    //             'lop' => $lop
    //         );
    //         return $p->Insert("sinhvien01", $data);
    //     }
    
    //     public function UpdateStudent($masv, $new_masv, $hodem, $ten, $lop) {
    //         $p = new ModelSQL;
    //         $result = $p->UpSQL("SELECT * FROM sinhvien01 WHERE masv='$masv'");
    //         if (mysqli_num_rows($result) == 0) {
    //             return false;
    //         }
    //         $oldData = mysqli_fetch_assoc($result);
    //         $hodem = !empty($hodem) ? $hodem : $oldData['hodem'];
    //         $ten = !empty($ten) ? $ten : $oldData['ten'];
    //         $lop = !empty($lop) ? $lop : $oldData['lop'];
    //         $data = array(
    //             'hodem' => $hodem,
    //             'ten' => $ten,
    //             'lop' => $lop
    //         );

    //         if (!empty($new_masv)) {
    //             $data['masv'] = $new_masv;
    //         }

    //         return $p->Update("sinhvien01", $data, "masv='$masv'");
    //     }
    
    //     public function DeleteStudent($masv) {
    //         $p = new ModelSQL;
    //         return $p->Delete("sinhvien01", "masv='$masv'");
    //     }
    // }
?>