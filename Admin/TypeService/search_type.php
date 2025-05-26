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

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $type_name = isset($_GET['type_name']) ? trim($_GET['type_name']) : '';

    if ($type_name) {
        // Điều kiện WHERE linh hoạt theo các tham số
        $query = "SELECT * FROM service_type WHERE 1=1";
        $params = [];
        $types = "";

        if ($type_name) {
            $query .= " AND LOWER(type_name) LIKE ?";
            $params[] = "%" . strtolower($type_name) . "%";
            $types .= "s";
        }

        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
    } else {
        // Nếu không có từ khóa tìm kiếm, trả về tất cả trung tâm
        $query = "SELECT * FROM service_type";
        $stmt = $conn->prepare($query);
    }

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $service_type = [];
        while ($row = $result->fetch_assoc()) {
            $service_type[] = $row;
        }

        echo json_encode(['success' => true, 'service_type' => $service_type]); // Sửa lỗi ở đây
    } else {
        echo json_encode(['success' => false, 'message' => 'Query execution failed.']);
    }

    $stmt->close();
}

?>