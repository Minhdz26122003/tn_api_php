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

   $type_name = $decodedData['type_name'] ?? '';

    $checkStmt = $conn->prepare("SELECT type_name FROM service_type WHERE type_name = ? ");
    $checkStmt->bind_param("s", $type_name);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows > 0) {
        $existing = $checkResult->fetch_assoc();
        if ($existing['type_name'] === $type_name) {
            echo json_encode([
                "status" => "error",
                "error" => ["code" => 409, "message" => "danh mục đã tồn tại"],
                "data" => null
            ]);
        }
    } else {
        $insertStmt = $conn->prepare("INSERT INTO service_type (type_name) 
        VALUES (?)");
        $insertStmt->bind_param("s", $type_name);
        
        if ($insertStmt->execute()) {        
               
            echo json_encode([
                "data" => [
                    "type_name" => $type_name                 
                ],
                "error" => ["code" => 0, "message" => "Thêm danh mục thành công"],
            ]);
        } else {
            echo json_encode([
                "status" => "error",
                "error" => ["code" => 500, "message" => "Lỗi khi thêm danh mục: " . $conn->error],
                "data" => null
            ]);
        }
        
        $insertStmt->close();
    }
    $checkStmt->close();
    $conn->close();
    
} else if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
} else {
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 405, "message" => "Phương thức không hợp lệ"],
        "data" => null
    ]);
}
?>