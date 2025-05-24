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
    echo json_encode([
        "success" => false,
        "message" => "Kết nối thất bại: " . $conn->connect_error
    ]);
    exit(); // Sử dụng exit() thay vì die() để trả về JSON hợp lệ
}

// Lấy token từ header
$headers = getallheaders();
$token = $headers["Authorization"] ?? "";

// Xác thực token (Bỏ comment nếu bạn muốn yêu cầu xác thực token)
// if (!verifyToken($token)) {
//     echo json_encode([
//         "success" => false,
//         "message" => "Token không hợp lệ hoặc đã hết hạn"
//     ]);
//     $conn->close();
//     exit();
// }

try {
    // Chỉ tính cho các lịch hẹn đã hoàn thành (status = 7) và đã thanh toán (payment.status = 1)
    $sql = "
        SELECT 
            p.total_price AS total_revenue
        FROM 
            appointment AS a
        JOIN 
            payment AS p ON a.appointment_id = p.appointment_id
        WHERE 
            a.status = 7 AND p.status = 1;
    ";

    $result = $conn->query($sql);

    if ($result === false) {
        throw new Exception($conn->error);
    }

    $row = $result->fetch_assoc();
    $total_revenue = $row['total_revenue'] ?? 0;
    echo json_encode([
        'success' => true,
        'total_revenue' => (float)$total_revenue, // Ép kiểu thành float để đảm bảo đúng kiểu dữ liệu số
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving total revenue',
        'error' => $e->getMessage()
    ]);
} finally {
    $conn->close();
}
?>