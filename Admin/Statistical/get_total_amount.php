<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

$conn = getDBConnection();

if ($conn->connect_error) {
    echo json_encode(["success" => false, "message" => "Kết nối thất bại: " . $conn->connect_error]);
    exit();
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

// Lấy tham số thời gian từ request (nếu có)
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;
$group_by = $_GET['group_by'] ?? 'day'; // 'day', 'month', 'year'

$sql_parts = [];
$params = [];
$types = "";

// Điều kiện thời gian
if ($start_date && $end_date) {
    $sql_parts[] = "payment_date BETWEEN ? AND ?";
    $params[] = $start_date . " 00:00:00";
    $params[] = $end_date . " 23:59:59";
    $types .= "ss";
} elseif ($start_date) {
    $sql_parts[] = "payment_date >= ?";
    $params[] = $start_date . " 00:00:00";
    $types .= "s";
} elseif ($end_date) {
    $sql_parts[] = "payment_date <= ?";
    $params[] = $end_date . " 23:59:59";
    $types .= "s";
}

// Chỉ lấy các thanh toán đã thành công (status = 1)
$sql_parts[] = "status = 1";

$where_clause = '';
if (!empty($sql_parts)) {
    $where_clause = 'WHERE ' . implode(' AND ', $sql_parts);
}

// Chọn định dạng ngày dựa trên group_by
$date_format_sql = '';
$date_column_alias = '';
if ($group_by === 'month') {
    $date_format_sql = "DATE_FORMAT(payment_date, '%Y-%m')";
    $date_column_alias = 'payment_month';
} elseif ($group_by === 'year') {
    $date_format_sql = "DATE_FORMAT(payment_date, '%Y')";
    $date_column_alias = 'payment_year';
} else { // default to 'day'
    $date_format_sql = "DATE_FORMAT(payment_date, '%Y-%m-%d')";
    $date_column_alias = 'payment_date_formatted';
}


$sql = "SELECT
            $date_format_sql AS $date_column_alias,
            SUM(CASE WHEN form = 1 THEN total_price ELSE 0 END) AS total_online,
            SUM(CASE WHEN form = 2 THEN total_price ELSE 0 END) AS total_offline,
            SUM(total_price) AS total
        FROM
            payment
        $where_clause
        GROUP BY
            $date_column_alias
        ORDER BY
            $date_column_alias ASC";

$stmt = $conn->prepare($sql);

if ($stmt === false) {
    echo json_encode(["success" => false, "message" => "Prepare failed: " . $conn->error]);
    $conn->close();
    exit();
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

if (!$stmt->execute()) {
    echo json_encode(["success" => false, "message" => "Execute failed: " . $stmt->error]);
    $stmt->close();
    $conn->close();
    exit();
}

$result = $stmt->get_result();
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode(["success" => true, "data" => $data]);

$stmt->close();
$conn->close();
?>