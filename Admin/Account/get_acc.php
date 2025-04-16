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
   
    $uid = isset($_GET['uid']) ? intval($_GET['uid']) : 0; 

    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
    if ($page < 1) $page = 1;
    if ($limit < 1) $limit = 10;
    $offset = ($page - 1) * $limit;
    
    // Đếm tổng số tài khoản (không bao gồm tài khoản có iduser truyền vào)
    $countSql = "SELECT COUNT(*) as total FROM users WHERE uid != ?";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param("i", $uid);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalAccounts = 0;
    if ($row = $countResult->fetch_assoc()) {
        $totalAccounts = intval($row['total']);
    }
    $countStmt->close();
    
    // Truy vấn lấy danh sách tài khoản với phân trang
    $sql = "SELECT * FROM users WHERE uid != ? LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $uid, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    $usersList = [];
    while ($row = $result->fetch_assoc()) {
        $usersList[] = $row;
    }
    $stmt->close();
    
    // Tính tổng số trang
    $totalPages = ceil($totalAccounts / $limit);
    
    $response = array(
        "data" => $usersList,
        "currentPage" => $page,
        "totalPages" => $totalPages,
        "totalAccounts" => $totalAccounts
        
    );
    
    echo json_encode($response);
} else {
    http_response_code(405);
    echo json_encode(["message" => "Phương thức không được hỗ trợ. Chỉ hỗ trợ GET."]);
}

$conn->close();
?>