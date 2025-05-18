<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token_user.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

checkToken();
$conn = getDBConnection();

$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
}

if (!isset($input['keyCert'], $input['time'], $input['uid'], $input['appointment_id'])) {
    echo json_encode([
        "status"=>"error",
        "error"=>["code"=>400,"message"=>"Thiếu tham số"],
        "data"=>null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$keyCert       = $input['keyCert'];
$time          = $input['time'];
$uid           = trim($input['uid']);
$appointmentId = intval($input['appointment_id']);

if (!isValidKey($keyCert, $time)) {
    echo json_encode([
        "status"=>"error",
        "error"=>["code"=>403,"message"=>"KeyCert không hợp lệ"],
        "data"=>null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $conn->prepare("
    SELECT payment_id, appointment_id,
           DATE_FORMAT(payment_date, '%Y-%m-%d %H:%i:%s') AS payment_date,
           form, status, total_price
    FROM payment
    WHERE appointment_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $appointmentId);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode([
        "status"=>"success",
        "error"=>["code"=>0, "message"=>"Lưu thông tin thanh toán thành công"],
        "data"=>$row
    ]);
} else {
    echo json_encode([
        "status"=>"error",
        "error"=>["code"=>404,"message"=>"Chưa có thông tin thanh toán"],
        "data"=>null
    ]);
}

$stmt->close();
$conn->close();
?>