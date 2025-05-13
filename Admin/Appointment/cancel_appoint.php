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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $appointment_id = $data['appointment_id'];
    $reason = $data['reason'];

    if (empty($appointment_id) || empty($reason)) {
        echo json_encode(['success' => false, 'message' => 'Thiếu dữ liệu']);
        exit;
    }

    $query = "UPDATE apointment SET status =6, reason = ? WHERE appointment_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('si', $reason, $appointment_id);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Hủy lịch hẹn thành công']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Hủy lịch hẹn thất bại']);
    }

    $stmt->close();
}
$conn->close();
?>  