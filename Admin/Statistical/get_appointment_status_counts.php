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
    
    $query = "SELECT
        SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS chua_xac_nhan,
        SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS bao_gia,
        SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS chap_nhan_bao_gia,
        SUM(CASE WHEN status = 3 THEN 1 ELSE 0 END) AS dang_sua,
        SUM(CASE WHEN status = 4 THEN 1 ELSE 0 END) AS hoan_thanh,
        SUM(CASE WHEN status = 5 THEN 1 ELSE 0 END) AS quyet_toan,
        SUM(CASE WHEN status = 6 THEN 1 ELSE 0 END) AS thanh_toan,
        SUM(CASE WHEN status = 7 THEN 1 ELSE 0 END) AS da_thanh_toan,
        SUM(CASE WHEN status = 8 THEN 1 ELSE 0 END) AS da_huy
    FROM appointment"; 

    $result = $conn->query($query);

    if ($result) {
        $counts = $result->fetch_assoc();

        // Chuyển đổi các giá trị string sang int
        foreach ($counts as $key => $value) {
            $counts[$key] = (int)$value;
        }

        echo json_encode([
            "success" => true,
            "data" => $counts
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Lỗi truy vấn cơ sở dữ liệu: " . $conn->error
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Phương thức không được hỗ trợ. Chỉ hỗ trợ GET."
    ]);
}

$conn->close();
?>