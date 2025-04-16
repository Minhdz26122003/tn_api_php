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
// Xử lý yêu cầu GET để lấy tất cả trung tâm
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sql = "SELECT * FROM center";
    $result = $conn->query($sql);

     // Kiểm tra nếu có dữ liệu trả về
     if ($result->num_rows > 0) {
        $trungtamList = [];
        while ($row = $result->fetch_assoc()) {
            $trungtamList[] = $row;
        }
        echo json_encode($trungtamList);
    } else {
        echo json_encode(["message" => "Không tìm thấy trung tâm nào."]);
    }
} else {
    http_response_code(405);
    echo json_encode(["message" => "Phương thức không được hỗ trợ. Chỉ hỗ trợ GET."]);
}

$conn->close();
?>
