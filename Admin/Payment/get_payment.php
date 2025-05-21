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


// chỉ hỗ trợ GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit(json_encode([
        "success" => false,
        "message" => "Phương thức không được hỗ trợ. Chỉ hỗ trợ GET."
    ]));
}

// lấy paging
$page   = max(1, intval($_GET['page']  ?? 1));
$limit  = max(1, intval($_GET['limit'] ?? 10));
$offset = ($page-1)*$limit;

// lấy status filter (0..4)
$filter = isset($_GET['status']) ? intval($_GET['status']) : 0;
// build điều kiện WHERE
$where = [];
switch ($filter) {
  case 1:  $where[] = "status = 0";                  break; // chưa thanh toán
  case 2:  $where[] = "status = 1";                  break; // đã thanh toán
  case 3:  $where[] = "status = 1 AND form = 1";     break; // offline
  case 4:  $where[] = "status = 1 AND form = 2";     break; // online
  default: /* 0 hoặc các giá trị khác = all */      break;
}
$where_sql = $where ? "WHERE ".implode(" AND ", $where) : "";

// --- 1) Tổng số bản ghi sau filter
$countSql = "SELECT COUNT(*) AS total FROM payment $where_sql";
$totalPayment = $conn->query($countSql)->fetch_assoc()['total'] ?? 0;

// --- 2) Thống kê chung (toàn bộ table, bỏ qua filter) để front-end vẽ tab counts
$statsSql = "
  SELECT
    COUNT(*) AS all_count,
    SUM(status=0) AS unpaid,
    SUM(status=1) AS paid,
    SUM(status=1 AND form=1) AS paid_offline,
    SUM(status=1 AND form=2) AS paid_online
  FROM payment
";
$stats = $conn->query($statsSql)->fetch_assoc();

// --- 3) Lấy data page
$dataSql = "
  SELECT payment_id, appointment_id, payment_date, form, status, total_price
  FROM payment
  $where_sql
  ORDER BY payment_date DESC
  LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($dataSql);
$stmt->bind_param("ii", $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($r = $result->fetch_assoc()) {
    $data[] = $r;
}
$stmt->close();

// build response
echo json_encode([
  "success"    => true,
  "data"       => $data,
  "pagination" => [
    "currentPage"=> $page,
    "totalPages" => ceil($totalPayment/$limit),
    "limit"      => $limit, 
    "totalItems" => $totalPayment
  ],
  "counts" => [
    "all"          => intval($stats['all_count']),
    "unpaid"       => intval($stats['unpaid']),
    "paid"         => intval($stats['paid']),
    "paid_offline" => intval($stats['paid_offline']),
    "paid_online"  => intval($stats['paid_online']),
  ]
], JSON_UNESCAPED_UNICODE);

$conn->close();