<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE , OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

$conn = getDBConnection();
// Kiểm tra kết nối
if ($conn->connect_error) {
    die("Kết nối thất bại: " . $conn->connect_error);
}
// Lấy dữ liệu từ yêu cầu JSON
$data = json_decode(file_get_contents("php://input"), true);

if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
    if (isset($data['id'])) {
        $id = $data['id'];
    
        $query = "DELETE FROM service_gara WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $id);
    
        if ($stmt->execute()) { 
            echo json_encode(['success' => true, 'message' => 'Xóa dịch vụ thành công']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Xóa dịch vụ không thành công']);
        }

        $stmt->close();
    }else{
        echo json_encode(['success' => false, 'message' => 'Thiếu mã trung tâm dich vụ']);
    }
}
$conn->close();
?>