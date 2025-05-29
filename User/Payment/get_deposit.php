<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token_user.php"; // Xác thực token của người dùng

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json"); 

// Kiểm tra token user
checkToken(); // Hàm này từ verify_token_user.php

$conn = getDBConnection();

// Xử lý các yêu cầu pre-flight OPTIONS
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Lấy input JSON (hỗ trợ cả POST và GET nếu cần, nhưng POST là tốt nhất cho các API)
$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (empty($input)) { // Fallback to GET for debugging/simplicity if no POST body
        $input = $_GET;
    }
}

// Validate params
if (!isset($input['keyCert'], $input['time'], $input['uid'], $input['appointment_id'])) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Thiếu tham số (keyCert, time, uid, appointment_id)"],
        "data"   => null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$keyCert       = $input['keyCert'];
$time          = $input['time'];
$uid           = trim($input['uid']);
$appointmentId = intval($input['appointment_id']);

// Check KeyCert
if (!isValidKey($keyCert, $time)) { // Hàm isValidKey này từ function.php
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 403, "message" => "KeyCert không hợp lệ hoặc đã hết hạn."],
        "data"   => null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Lấy thông tin đặt cọc
$stmt = $conn->prepare("
    SELECT 
        deposit_id, 
        appointment_id,
        amount, 
        status, 
        DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS deposit_date
    FROM deposits
    WHERE appointment_id = ?
    LIMIT 1
");

if (!$stmt) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 500, "message" => "Lỗi chuẩn bị câu lệnh: " . $conn->error],
        "data"   => null
    ], JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}

$stmt->bind_param("i", $appointmentId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $depositData = $result->fetch_assoc();
    // Convert amount to float for consistent data type in JSON
    $depositData['amount'] = (float)$depositData['amount'];

    echo json_encode([
        "status" => "success",
        "error"=>["code"=>0, "message"=>"Lấy thông tin thành công"],
        "data"   => ["deposit" => $depositData]
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        "status" => "success", // Vẫn là success nếu không tìm thấy, chỉ là không có deposit
         "error"=>["code"=>404,"message"=>"Chưa có thông tin"],
        "data"   =>  null,
    ], JSON_UNESCAPED_UNICODE);
}

$stmt->close();
$conn->close();
?>