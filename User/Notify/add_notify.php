<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token_user.php";
require_once "../../vendor/autoload.php"; 
require_once __DIR__ . '../../Notify/NotificationService.php';

use Notify\NotificationService;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET vascular, POST, PUT, DELETE, OPTIONS");
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


if (!isset($input['time'], $input['keyCert'])) {
    http_response_code(400);
    exit(json_encode([
        "status" => "error",
        "error" => ["code" => 400, "message" => "Thiếu keyCert hoặc time"],
        "data" => null
    ]));
}

$time = $input['time'];
$keyCert = $input['keyCert'];

if (!isValidKey($keyCert, $time)) {
    http_response_code(403);
    exit(json_encode([
        "status" => "error",
        "error" => ["code" => 403, "message" => "keyCert không hợp lệ hoặc hết hạn"],
        "data" => null
    ]));
}
// Validate các trường bắt buộc
foreach (['uid','title','body'] as $f) {
  if (empty($input[$f])) {
    http_response_code(400);
    exit(json_encode(['status'=>'error','error'=>['code'=>400,'message'=>"Thiếu $f"]]));
  }
}

$notifier = new NotificationService();
$ok = $notifier->createInDatabase([
  'uid'   => (int)$input['uid'],
  'title' => $input['title'],
  'body'  => $input['body'],
]);

if ($ok) {
  echo json_encode(['status'=>'success','error'=>['code'=>0,'message'=>'OK']]);
} else {
  http_response_code(500);
  echo json_encode(['status'=>'error','error'=>['code'=>500,'message'=>'Lưu thất bại']]);
}
?>