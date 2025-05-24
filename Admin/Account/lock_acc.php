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

$data = json_decode(file_get_contents("php://input"), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($data['uid'])) {
        $uid = $data['uid'];

        $query = "UPDATE users SET status = 3 WHERE uid = ?";
        

        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $uid);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Tài khoản đã được khóa.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Xác nhận tài khoản chưa được khóa.']);
        }


        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Thiếu thông tin uid.']);
    }
} else {
    echo json_encode(['message' => 'Phương thức không được hỗ trợ.']);
}

$conn->close();
?>