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
    $uid = isset($_GET['uid']) ? intval($_GET['uid']) : 0; 
    $username = isset($_GET['username']) ? trim($_GET['username']) : '';

    if ($username) {
        $query = "SELECT * FROM users WHERE LOWER(username) LIKE ? AND uid != ?";
        $stmt = $conn->prepare($query);
        $search = "%" . strtolower($username) . "%";
        $stmt->bind_param('si', $search, $uid);
    } else {
        $query = "SELECT * FROM users WHERE uid != ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $uid); 
    }

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $accounts = [];
        while ($row = $result->fetch_assoc()) {
            $accounts[] = $row;
        }

        // Trả về kết quả JSON
        echo json_encode(['success' => true, 'accounts' => $accounts]);
    } else {
        echo json_encode(['success' => false, 'message' => '.']);
    }

    $stmt->close();
}
?>