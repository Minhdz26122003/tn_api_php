<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE,OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

$conn = getDBConnection();
// Kiểm tra kết nối
if ($conn->connect_error) {
    die("Kết nối thất bại: " . $conn->connect_error);
}

if($_SERVER['REQUEST_METHOD'] === 'GET'){
    $page= isset($_GET['page']) ? intval($_['page']) : 1;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
    if ($page < 1) $page = 1;
    if ($limit < 1) $limit = 10;
    $offset = ($page - 1) * $limit;

    $countSql = "SELECT COUNT(*) as total FROM review ";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalReview = 0;
    if ($row = $countResult->fetch_assoc()) {
        $totalReview = intval($row['total']);
    }
    $countStmt->close();

    // Truy vấn lấy danh sách tài khoản với phân trang
    $sql = "SELECT * FROM review WHERE LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    $reviewList = [];
    while ($row = $result->fetch_assoc()) {
        $reviewList[] = $row;
    }
    $stmt->close();
    
    // Tính tổng số trang
    $totalPages = ceil($totalReview / $limit);
    
    $response = array(
        "data" => $reviewList,
        "currentPage" => $page,
        "totalPages" => $totalPages,
        "totalReview" => $totalReview,
        

    );
}
?>