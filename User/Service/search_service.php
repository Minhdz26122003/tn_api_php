<?php
//search_service.php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

$conn = getDBConnection();
$conn->set_charset("utf8mb4");

// Lấy input JSON (kiểm tra cả POST và raw input)
$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
}

// Validate param
if (!isset($input['keyCert'], $input['time'], $input['uid'])) {
    echo json_encode(["status"=>"error","error"=>["code"=>400,"message"=>"Missing parameters"],"items"=>null]);
    exit;
}

$time       = $input['time'];
$keyCert    = $input['keyCert'];
$uid        = trim($input['uid']); 
$queryStr   = isset($input['query']) ? "%" . $conn->real_escape_string($input['query']) . "%" : "%%";

// Lấy các tham số filter, chấp nhận null nếu không được gửi
$typeId     = isset($input['type_id']) ? intval($input['type_id']) : null;
$minPrice   = isset($input['min_price']) ? floatval($input['min_price']) : null; // Thay 0.0 thành null
$maxPrice   = isset($input['max_price']) ? floatval($input['max_price']) : null; // Thay PHP_FLOAT_MAX thành null

// Thời gian mặc định
$minTime    = isset($input['min_time']) ? $input['min_time'] : "00:00:00"; 
$maxTime    = isset($input['max_time']) ? $input['max_time'] : "23:59:59"; 

// Check keyCert (nếu cần)
if (!isValidKey($keyCert, $time)) {
    echo json_encode(["status"=>"error","error"=>["code"=>403,"message"=>"Invalid KeyCert"],"items"=>null]);
    exit;
}

// Xây dựng truy vấn động dựa trên các bộ lọc
$sql = "SELECT s.service_id, s.service_name, s.description, s.service_img, s.type_id, s.price, s.time 
        FROM service s
        JOIN service_type t ON s.type_id = t.type_id
        WHERE (s.service_name LIKE ? OR s.description LIKE ?)"; // Bọc điều kiện LIKE trong ngoặc đơn

$params = [$queryStr, $queryStr];
$types = "ss";

if ($typeId !== null) { 
    $sql .= " AND s.type_id = ?";
    $params[] = $typeId;
    $types .= "i";
}

// Thêm điều kiện giá nếu minPrice hoặc maxPrice được cung cấp
if ($minPrice !== null) {
    $sql .= " AND s.price >= ?";
    $params[] = $minPrice;
    $types .= "d";
}

if ($maxPrice !== null) {
    $sql .= " AND s.price <= ?";
    $params[] = $maxPrice;
    $types .= "d";
}

// Thêm điều kiện thời gian (luôn có giá trị mặc định)
$sql .= " AND s.time >= ? AND s.time <= ?";
$params[] = $minTime;
$params[] = $maxTime;
$types .= "ss";

$sql .= " ORDER BY s.service_name ASC";


$stmt = $conn->prepare($sql);

if ($stmt === false) {
    echo json_encode(["status"=>"error","error"=>["code"=>500,"message"=>"Prepare failed: " . $conn->error],"items"=>null]);
    exit;
}

// Dynamic binding of parameters
call_user_func_array([$stmt, 'bind_param'], array_merge([$types], reference_values($params)));


$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode([
    "status" => "success",
    "error"  => ["code"=> 0,"message"=>"success"],
    "items"  => $rows
]);

$conn->close();

// Hàm hỗ trợ để lấy tham chiếu cho bind_param
function reference_values(&$arr) {
    $refs = array();
    foreach ($arr as $key => $value) {
        $refs[$key] = &$arr[$key];
    }
    return $refs;
}
?>