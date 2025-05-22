<?php
// add_offline.php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token_user.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

checkToken();

$conn = getDBConnection();

$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
}

if (!isset($input['keyCert'], $input['time'], $input['payment_id'])) {
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 400, "message" => "Thiếu tham số"],
        "data" => null
    ]);
    exit;
}

$keyCert   = $input['keyCert'];
$time      = $input['time'];
$paymentId = intval($input['payment_id']);

if (!isValidKey($keyCert, $time)) {
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 403, "message" => "KeyCert không hợp lệ"],
        "data" => null
    ]);
    exit;
}

// Kiểm tra trạng thái hiện tại của thanh toán
$checkStmt = $conn->prepare("SELECT form, status FROM payment WHERE payment_id = ?");
$checkStmt->bind_param("i", $paymentId);
$checkStmt->execute();
$result = $checkStmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 404, "message" => "Không tìm thấy bản ghi thanh toán"],
        "data" => null
    ]);
    $checkStmt->close();
    $conn->close();
    exit;
}

$row = $result->fetch_assoc();
$form = intval($row['form']);
$status = intval($row['status']);

$checkStmt->close();

if ($form == 2 && $status == 0) {
    // Đã xác nhận thanh toán offline rồi
    echo json_encode([
        "status" => "exists",
        "error" => ["code" => 409, "message" => "Đã xác nhận thanh toán offline trước đó, vui lòng chờ xử lý."],
        "data" => ["payment_id" => $paymentId]
    ]);
    $conn->close();
    exit;
}

// Cập nhật sang thanh toán offline
$updateStmt = $conn->prepare("UPDATE payment SET status = 0, form = 2 WHERE payment_id = ?");
$updateStmt->bind_param("i", $paymentId);

if ($updateStmt->execute()) {
    echo json_encode([
        "status" => "success",
        "error" => ["code" => 0, "message" => "Xác nhận thanh toán offline thành công!"],
        "data" => ["payment_id" => $paymentId]
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 500, "message" => "Cập nhật phương thức thanh toán không thành công!"],
        "data" => null
    ]);
}

$updateStmt->close();
$conn->close();
