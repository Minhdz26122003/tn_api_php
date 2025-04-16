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

$data = json_decode(file_get_contents("php://input"), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($data['appointment_id'])) {
        $appointment_id = $data['appointment_id'];

      
        $query = "UPDATE appointment SET status= 1 WHERE appointment_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $appointment_id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Lịch hẹn đã được xác nhận.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Xác nhận lịch hẹn không thành công.']);
        }

        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Thiếu thông tin appointment_id.']);
    }
} else {
    echo json_encode(['message' => 'Phương thức không được hỗ trợ.']);
}

$conn->close();
?>