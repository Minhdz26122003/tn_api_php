<?php
// add_payment.php
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

// Giữ nguyên các tham số bắt buộc ban đầu, không cần deposit_amount ở đây
foreach (['keyCert','time','uid','appointment_id','form','status','total_price'] as $k) {
    if (!isset($input[$k])) {
        echo json_encode([
            "status"=>"error",
            "error"=>["code"=>400, "message"=>"Thiếu tham số $k"],
            "data"=>null
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$time           = $input['time'];
$keyCert        = $input['keyCert'];
$appId          = intval($input['appointment_id']);
$form           = intval($input['form']);
$status         = intval($input['status']);
$totalPrice     = floatval($input['total_price']); // Đây là tổng tiền dịch vụ + phụ tùng

// Check keyCert
if (!isValidKey($keyCert, $time)) {
    echo json_encode([
        "status"=>"error",
        "error"=>["code"=>403, "message"=>"KeyCert không hợp lệ"],
        "data"=>null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Lấy số tiền đặt cọc từ bảng deposits
$depositAmount = 0.0;
$stmtDeposit = $conn->prepare("SELECT amount FROM deposits WHERE appointment_id = ? AND status = 1"); // status = 1 (Paid)
$stmtDeposit->bind_param("i", $appId);
$stmtDeposit->execute();
$resultDeposit = $stmtDeposit->get_result();

if ($resultDeposit->num_rows > 0) {
    $rowDeposit = $resultDeposit->fetch_assoc();
    $depositAmount = floatval($rowDeposit['amount']); //
}
$stmtDeposit->close();

// Tính toán lại total_price sau khi trừ tiền đặt cọc
$finalTotalPrice = $totalPrice - $depositAmount;
if ($finalTotalPrice < 0) {
    $finalTotalPrice = 0; // Đảm bảo tổng tiền không âm
}

// Insert vào table payment
$stmt = $conn->prepare("
    INSERT INTO payment (appointment_id, payment_date, form, status, total_price)
    VALUES (?, NOW(), ?, ?, ?)
");
// Sử dụng $finalTotalPrice thay vì $totalPrice ban đầu
$stmt->bind_param("iiid", $appId, $form, $status, $finalTotalPrice);

if ($stmt->execute()) {
    $paymentId = $conn->insert_id;
    echo json_encode([
        "status"=>"success",
        "error"=>["code"=>0, "message"=>"Lưu thông tin thanh toán thành công"],
        "items"=>["payment_id"=>$paymentId],
        "final_total_price" => $finalTotalPrice, // Có thể thêm để log hoặc debug
        "original_total_price" => $totalPrice,
        "deposit_amount_used" => $depositAmount
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        "status"=>"error",
        "error"=>["code"=>500, "message"=>"Không thể lưu thông tin thanh toán"],
        "data"=>null
    ], JSON_UNESCAPED_UNICODE);
}

$stmt->close();
$conn->close();
?>