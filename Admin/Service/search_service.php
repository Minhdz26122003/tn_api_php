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
// Lấy dữ liệu từ yêu cầu JSON
$data = json_decode(file_get_contents("php://input"), true);
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $service_name = isset($_GET['service_name']) ? trim($_GET['service_name']) : '';
    $minPrice = isset($_GET['minPrice']) ? (int)$_GET['minPrice'] : 0;
    $maxPrice = isset($_GET['maxPrice']) ? (int)$_GET['maxPrice'] : 10000000;
    $query = "SELECT * FROM service WHERE price BETWEEN ? AND ?";
    if (!empty($service_name)) {
        $query .= " AND LOWER(service_name) LIKE ?";
    }
 
    $stmt = $conn->prepare($query);

    if (!empty($service_name)) {
        $search = '%' . strtolower($service_name) . '%';
        $stmt->bind_param('iis', $minPrice, $maxPrice, $search);
    } else {
        $stmt->bind_param('ii', $minPrice, $maxPrice);
    }

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $services = [];
        while ($row = $result->fetch_assoc()) {
            $services[] = $row;
        }

        echo json_encode(['success' => true, 'services' => $services]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Lỗi tìm kiếm.']);
    }
    $stmt->close();
}

?>