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
$input = json_decode(file_get_contents("php://input"), true);
if (!isset($input['data'])) {
    echo json_encode(["success" => false, "message" => "Dữ liệu không hợp lệ"]);
    exit();
}
// Giải mã Base64 với UTF-8 an toàn
$decodedJson =urldecode(base64_decode($input['data']));
$decodedData = json_decode($decodedJson, true);
if (!$decodedData) {
    echo json_encode(["success" => false, "message" => "Lỗi giải mã dữ liệu"]);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
   // Lấy dữ liệu từ request
   $type_id  = $decodedData['type_id'] ?? '';
   $type_name = $decodedData['type_name'] ?? '';


    $query = "UPDATE service_type SET type_name = ?  WHERE type_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('si', $type_name, $type_id); // Sửa lỗi ở đây

    if ($stmt->execute()) {
        echo json_encode([
            "success" => true,
            "message" => "Sửa thành công"
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Sửa không thành công",
            "error" => ["code" => 405, "message" => "Sửa không thành công"]
        ]);
    }

    $stmt->close();
}
?>