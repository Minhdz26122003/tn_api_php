<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

$conn = getDBConnection();
// Kiểm tra kết nối
if ($conn->connect_error) {
    die("Kết nối thất bại: " . $conn->connect_error);
}
// Lấy token từ header
$headers = getallheaders();
$token = $headers["Authorization"] ?? "";

// Xác thực token
if (!verifyToken($token)) {
    echo json_encode([
        "success" => false,
        "message" => "Token không hợp lệ hoặc đã hết hạn"
    ]);
    $conn->close();
    exit();
}
// Lấy dữ liệu từ yêu cầu JSON
$data = json_decode(file_get_contents("php://input"), true);

if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
    if (isset($data['gara_id'])) {
        $gara_id = $data['gara_id'];
    
        $query = "DELETE FROM center WHERE gara_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $gara_id);
    
        if ($stmt->execute()) { 
            echo json_encode(['success' => true, 'message' => 'Xóa trung tâm thành công']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Xóa trung tâm không thành công']);
        }

        $stmt->close();
    }else{
        echo json_encode(['success' => false, 'message' => 'Thiếu mã trung tâm']);
    }
}
?>