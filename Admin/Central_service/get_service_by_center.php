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
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $gara_id = isset($_GET['gara_id']) ? $_GET['gara_id'] : null;

    if ($gara_id === null) {
        echo json_encode(["message" => "Thiếu tham số gara_id."]);
        http_response_code(400);
        $conn->close();
        exit();
    }

    $sql = "
        SELECT dv.service_id, dv.service_name
        FROM service dv
        WHERE dv.service_id NOT IN (
            SELECT service_id
            FROM service_gara
            WHERE gara_id = ?
        )
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $gara_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $services = [];
        while ($row = $result->fetch_assoc()) {
            $services[] = $row;
        }
        echo json_encode($services); 
    } else {
        echo json_encode(["message" => "Không tìm thấy dịch vụ nào."]);
    }

    $stmt->close();
} else {
    http_response_code(405); 
    echo json_encode(["message" => "Phương thức không được hỗ trợ. Chỉ hỗ trợ GET."]);
}
$conn->close();
?>