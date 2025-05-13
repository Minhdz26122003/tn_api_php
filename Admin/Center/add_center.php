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

   $gara_name = $decodedData['gara_name'] ?? '';
   $gara_address = $decodedData['gara_address'] ?? '';
   $phone = $decodedData['phone'] ?? '';
   $email = $decodedData['email'] ?? '';
   $gara_img = $decodedData['gara_img'] ?? '';
   $x_location = $decodedData['x_location'] ?? '';
   $y_location = $decodedData['y_location'] ?? '';

    $checkStmt = $conn->prepare("SELECT gara_name FROM gara WHERE gara_name = ? ");
    $checkStmt->bind_param("s", $gara_name);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows > 0) {
        $existing = $checkResult->fetch_assoc();
        if ($existing['gara_name'] === $gara_name) {
            echo json_encode([
                "status" => "error",
                "error" => ["code" => 409, "message" => "Trung tâm đã tồn tại"],
                "data" => null
            ]);
        }
    } else {
        $insertStmt = $conn->prepare("INSERT INTO gara (gara_name, gara_address, phone,email, gara_img, x_location,y_location) 
        VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insertStmt->bind_param("sssssss", $gara_name, $gara_address, $phone, $email, $gara_img, $x_location, $y_location);
        
        if ($insertStmt->execute()) {        
               
            echo json_encode([
                "data" => [
                    "gara_name" => $gara_name,
                    "gara_address" => $gara_address,
                    "phone" => $phone,
                    "email" => $email,
                    "gara_img" => $gara_img,
                    "x_location" => $x_location,
                    "y_location" => $y_location
                   
                ],
                "error" => ["code" => 0, "message" => "Thêm trung tâm thành công"],
            ]);
        } else {
            echo json_encode([
                "status" => "error",
                "error" => ["code" => 500, "message" => "Lỗi khi thêm trung tâm: " . $conn->error],
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