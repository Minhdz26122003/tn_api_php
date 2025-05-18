<?php
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
      "status"=>"error",
      "error"=>["code"=>400,"message"=>"Thiếu tham số"],
      "data"=>null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$keyCert   = $input['keyCert'];
$time      = $input['time'];
$paymentId = intval($input['payment_id']);

if (!isValidKey($keyCert, $time)) {
    echo json_encode([
      "status"=>"error",
      "error"=>["code"=>403,"message"=>"KeyCert không hợp lệ"],
      "data"=>null
    ]);
    exit;
}

// Cập nhật status = 0, form = 2
$stmt = $conn->prepare("
  UPDATE payment SET status = 0, form = 2 WHERE payment_id = ?");
$stmt->bind_param("i", $paymentId);

if ($stmt->execute()) {
    echo json_encode([
       "status"=>"success",
       "error"=>["code"=> 0, "message"=>"Cập nhật phường thức thanh toán thành công!"],
        "data"=>["payment_id"=>$paymentId],
    ]);
} else {
    echo json_encode([
      "status"=>"error",
      "error"=>["code"=>500,"message"=>"Cập nhật phường thức thanh toán không thành công!"],
      "data"=>null
    ]);
}

$stmt->close();
$conn->close();
?>