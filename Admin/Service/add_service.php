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
   $service_name = $decodedData['service_name'] ?? '';
   $type_id = $decodedData['type_id'] ?? '';
   $description = $decodedData['description'] ?? '';
   $price = $decodedData['price'] ?? '';
   $service_img = $decodedData['service_img'] ?? '';
   $time = $decodedData['time'] ?? '';
   
   // Kiểm tra đã tồn tại chưa
    $checkStmt = $conn->prepare("SELECT service_name FROM service WHERE service_name = ? ");
    $checkStmt->bind_param("s", $service_name);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows > 0) {
        $existing = $checkResult->fetch_assoc();
        if ($existing['service_name'] === $service_name) {
            echo json_encode([
                "status" => "error",
                "error" => ["code" => 409, "message" => "Dịch vụ đã tồn tại"],
                "data" => null
            ]);
        }
    } else {
        $insertStmt = $conn->prepare("INSERT INTO service (service_name,type_id,description, price, service_img, time) 
        VALUES (?, ?, ?, ?, ? ,?)");
        $insertStmt->bind_param("sisiss", $service_name,$type_id, $description, $price, $service_img, $time);
        
        if ($insertStmt->execute()) {        
               
            echo json_encode([
                "data" => [
                    "service_name" => $service_name,
                    "description" => $description,
                    "price" => $price,
                    "service_img" => $service_img,
                    "time" => $time
                   
                ],
                "error" => ["code" => 0, "message" => "Thêm dịch vụ thành công"],
            ]);
        } else {
            echo json_encode([
                "status" => "error",
                "error" => ["code" => 500, "message" => "Lỗi khi thêm dịch vụ: " . $conn->error],
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