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
        if (!$table) {
            throw new Exception('Missing table name');
        }
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

?>