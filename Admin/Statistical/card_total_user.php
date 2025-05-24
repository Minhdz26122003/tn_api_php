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
    $sql = "SELECT COUNT(uid) AS total_user FROM users WHERE status != 3";
    $result = $conn->query($sql);

    if ($result === false) {
        throw new Exception($conn->error);
    }

    $total_user = $result->fetch_assoc()['total_user'];

    echo json_encode([
        'success' => true,
        'total_user' => (float)$total_user,
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving users statistics',
        'error' => $e->getMessage()
    ]);
}

$conn->close();
?>
