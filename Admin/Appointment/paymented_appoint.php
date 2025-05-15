<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
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
$appointment_id = intval($data['appointment_id']);

// Bắt đầu transaction
$conn->begin_transaction();
try {
    // Cập nhật payment.status = 1
    $updatePay = "
      UPDATE payment
      SET status = 1, payment_date = NOW()
      WHERE appointment_id = ?
    ";
    $stmt = $conn->prepare($updatePay);
    $stmt->bind_param("i", $appointment_id);
    if (!$stmt->execute()) {
        throw new Exception("Cập nhật payment thất bại");
    }
    $stmt->close();

    // Cập nhật appointment.status = 7
    $updateApp = "
      UPDATE appointment
      SET status = 7
      WHERE appointment_id = ?
    ";
    $stmt2 = $conn->prepare($updateApp);
    $stmt2->bind_param("i", $appointment_id);
    if (!$stmt2->execute()) {
        throw new Exception("Cập nhật appointment thất bại");
    }
    $stmt2->close();

    
    $conn->commit();
    echo json_encode(["success" => true, "message" => "Xác nhận thanh toán thành công"]);
} catch (Exception $e) {
    
    $conn->rollback();
    http_response_code(500);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}

$conn->close();
