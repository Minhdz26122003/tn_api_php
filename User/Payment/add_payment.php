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
$totalPrice     = floatval($input['total_price']);

// Check keyCert
if (!isValidKey($keyCert, $time)) {
    echo json_encode([
        "status"=>"error",
        "error"=>["code"=>403, "message"=>"KeyCert không hợp lệ"],
        "data"=>null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Insert vào table payment
$stmt = $conn->prepare("
    INSERT INTO payment (appointment_id, payment_date, form, status, total_price)
    VALUES (?, NOW(), ?, ?, ?)
");
$stmt->bind_param("iiid", $appId, $form, $status, $totalPrice);

if ($stmt->execute()) {
    $paymentId = $conn->insert_id;
    echo json_encode([
        "status"=>"success",
        "error"=>["code"=>0, "message"=>"Lưu thông tin thanh toán thành công"],
        "items"=>["payment_id"=>$paymentId],
        
    ]);
} else {
    echo json_encode([
        "status"=>"error",
        "error"=>["code"=>500, "message"=>"Không thể lưu thông tin thanh toán"],
        "data"=>null
    ]);
}

$stmt->close();
$conn->close();
?>
