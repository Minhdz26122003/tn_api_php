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
try {
    $sql = "SELECT COUNT(car_id) AS total_car FROM car WHERE status != 1";
    $result = $conn->query($sql);

    if ($result === false) {
        throw new Exception($conn->error);
    }

    $total_car = $result->fetch_assoc()['total_car'];

    echo json_encode([
        'success' => true,
        'total_car' => (float)$total_car,
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving appointment statistics',
        'error' => $e->getMessage()
    ]);
}

$conn->close();
?>
