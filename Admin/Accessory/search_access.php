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
    $accessory_name = isset($_GET['accessory_name']) ? trim($_GET['accessory_name']) : '';
    $minPrice = isset($_GET['minPrice']) ? (int)$_GET['minPrice'] : 0;
    $maxPrice = isset($_GET['maxPrice']) ? (int)$_GET['maxPrice'] : 10000000;
    $query = "SELECT * FROM accessory WHERE price BETWEEN ? AND ?";
    if (!empty($accessory_name)) {
        $query .= " AND LOWER(accessory_name) LIKE ?";
    }
 
    $stmt = $conn->prepare($query);

    if (!empty($accessory_name)) {
        $search = '%' . strtolower($accessory_name) . '%';
        $stmt->bind_param('iis', $minPrice, $maxPrice, $search);
    } else {
        $stmt->bind_param('ii', $minPrice, $maxPrice);
    }

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $accessorys = [];
        while ($row = $result->fetch_assoc()) {
            $accessorys[] = $row;
        }

        echo json_encode(['success' => true, 'accessorys' => $accessorys]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Lỗi tìm kiếm.']);
    }
    $stmt->close();
}

?>