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
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
    if ($page < 1) $page = 1;
    if ($limit < 1) $limit = 10;
    $offset = ($page - 1) * $limit;

    $countSql = "SELECT COUNT(*) as total FROM service_gara";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $total = 0;
    if ($row = $countResult->fetch_assoc()) {
        $total = intval($row['total']);
    }
    $countStmt->close();

    // Sửa câu truy vấn SQL
    $sql = "SELECT dvtt.id, tt.gara_name, dv.service_name
        FROM service_gara dvtt
        JOIN center tt ON dvtt.gara_id = tt.gara_id
        JOIN service dv ON dvtt.service_id  = dv.service_id
        LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    $central_servce_List = [];
    while ($row = $result->fetch_assoc()) {
        $central_servce_List[] = $row;
    }
    $stmt->close();

    $totalPages = ceil($total / $limit);

    $response = array(
        "data" => $central_servce_List,
        "currentPage" => $page,
        "totalPages" => $totalPages,
        "total" => $total,
    );
    echo json_encode($response);
} else {
    http_response_code(405);
    echo json_encode(["message" => "Phương thức không được hỗ trợ. Chỉ hỗ trợ GET."]);
}
$conn->close();
?>