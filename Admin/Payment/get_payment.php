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
$appointment_id = intval($_GET["appointment_id"] ?? 0);
if ($appointment_id < 1) {
    http_response_code(400);
    die(json_encode(["success"=>false, "message"=>"Invalid appointment_id"]));
}

$appointment_id = intval($_GET["appointment_id"] ?? 0);
if ($appointment_id < 1) {
    http_response_code(400);
    die(json_encode(["success"=>false,"message"=>"Invalid appointment_id"]));
}

// Lấy chi tiết dịch vụ
$sql1 = "SELECT da.service_id, s.service_name, s.price
         FROM detail_appointment da
         JOIN service s ON s.service_id = da.service_id
         WHERE da.appointment_id = ?";
$stmt1 = $conn->prepare($sql1);
$stmt1->bind_param("i",$appointment_id);
$stmt1->execute();
$services = $stmt1->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt1->close();

// Lấy chi tiết phụ tùng
$sql2 = "SELECT ap.accessory_id, a.accessory_name, ap.quantity,
                a.price AS unit_price,
                (ap.quantity * a.price) AS sub_total
         FROM accessory_payment ap
         JOIN accessory a ON a.accessory_id = ap.accessory_id
         WHERE ap.appointment_id = ?";
$stmt2 = $conn->prepare($sql2);
$stmt2->bind_param("i",$appointment_id);
$stmt2->execute();
$parts = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt2->close();

// Tính tổng
$service_total = array_sum(array_map(fn($r)=>floatval($r['price']), $services));
$parts_total   = array_sum(array_map(fn($r)=>floatval($r['sub_total']), $parts));
$total         = $service_total + $parts_total;

echo json_encode([
    "success"       => true,
    "services"      => $services,
    "parts"         => $parts,
    "service_total" => $service_total,
    "parts_total"   => $parts_total,
    "total"         => $total
], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);

$conn->close();
?>
