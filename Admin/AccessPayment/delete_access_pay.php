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
if ($conn->connect_error) {
    
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Lỗi kết nối cơ sở dữ liệu: " . $conn->connect_error
    ]);
    exit();
}

$headers = getallheaders();
$token = $headers["Authorization"] ?? "";


if (!verifyToken($token)) {
    echo json_encode(["success" => false, "message" => "Token không hợp lệ hoặc đã hết hạn"]);
    $conn->close();
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' || $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lấy dữ liệu từ request body (nếu là DELETE và dữ liệu gửi trong body)
    $data = json_decode(file_get_contents("php://input"), true);

     if (isset($data['appointment_id'], $data['accessory_id'])) {
        $appointment_id = (int)$data['appointment_id'];
        $accessory_id  = (int)$data['accessory_id'];

        // Kiểm tra xem bản ghi có tồn tại chưa
        $check = $conn->prepare("
            SELECT 1 
            FROM accessory_payment 
            WHERE appointment_id = ? AND accessory_id = ?
        ");
        $check->bind_param("ii", $appointment_id, $accessory_id);
        $check->execute();
        $res = $check->get_result();
        $check->close();

        if ($res->num_rows === 0) {
            http_response_code(404); // Not Found
            echo json_encode([
                "success" => false,
                "message" => "Không tìm thấy phụ tùng này trong hoá đơn."
            ]);
            $conn->close();
            exit();
        }

        // Thực hiện xóa
        $stmt = $conn->prepare("
            DELETE FROM accessory_payment 
            WHERE appointment_id = ? AND accessory_id = ?
        ");
        $stmt->bind_param("ii", $appointment_id, $accessory_id);

        if ($stmt->execute()) {
            echo json_encode([
                "success" => true,
                "message" => "Xóa phụ tùng khỏi hoá đơn thành công."
            ]);
        } else {
            http_response_code(500); // Internal Server Error
            echo json_encode([
                "success" => false,
                "message" => "Xảy ra lỗi khi xóa: " . $stmt->error
            ]);
        }
        $stmt->close();
    } else {
        http_response_code(400); // Bad Request
        echo json_encode([
            "success" => false,
            "message" => "Thiếu thông tin 'appointment_id' hoặc 'accessory_id'.",
            
        ]);
    }
} else {
    http_response_code(405); // Method Not Allowed
    echo json_encode([
        "success" => false,
        "message" => "Phương thức không được hỗ trợ. Vui lòng dùng DELETE hoặc POST."
    ]);
}

$conn->close();
?>