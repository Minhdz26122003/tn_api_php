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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   
    $id = $decodedData['id'] ?? '';
    $gara_id = $decodedData['gara_id'] ?? '';
    $service_id = $decodedData['service_id'] ?? '';
    
    if (empty($id) || empty($gara_id) || empty($service_id)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Thiếu id, gara_id hoặc service_id",
            "error" => ["code" => 400, "message" => "Dữ liệu không hợp lệ"]
        ]);
        exit();
    }

   
    $query = "UPDATE service_gara SET gara_id = ?, service_id = ? WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('iii', $gara_id, $service_id, $id);

    if ($stmt->execute()) {
        echo json_encode([
            "success" => true,
            "message" => "Sửa thành công"
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Sửa không thành công",
            "error" => ["code" => 500, "message" => $conn->error]
        ]);
    }

    $stmt->close();
}
?>