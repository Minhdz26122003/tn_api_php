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
    die(json_encode(["success" => false, "message" => "Lỗi kết nối cơ sở dữ liệu: " . $conn->connect_error]));
}


$headers = getallheaders();
$token = $headers["Authorization"] ?? "";


if (!verifyToken($token)) {
    echo json_encode(["success" => false, "message" => "Token không hợp lệ hoặc đã hết hạn"]);
    $conn->close();
    exit();
}

$appointment_id = intval($_GET["appointment_id"] ?? 0);
if ($appointment_id < 1) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "appointment_id không hợp lệ"]);
    exit;
}

$sql = "SELECT ap.accessory_payment_id,
               ap.accessory_id,
               a.accessory_name,
               ap.quantity,
               a.price AS unit_price
        FROM accessory_payment ap
        JOIN accessory a ON a.accessory_id = ap.accessory_id
        WHERE ap.appointment_id = ?
        ORDER BY ap.accessory_payment_id ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $appointment_id);
$stmt->execute();
$res = $stmt->get_result();

$list = [];
while ($row = $res->fetch_assoc()) {
    $list[] = $row;
}

echo json_encode([
    "success" => true,
    "parts"   => $list
]);

$stmt->close();
$conn->close();
?>