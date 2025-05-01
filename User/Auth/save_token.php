<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token_user.php";
require '../../vendor/autoload.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

checkToken();

$conn = getDBConnection();

$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
}

// Kiểm tra keyCert và time có gửi lên không?
if (!isset($input['time'], $input['keyCert'])) {
    http_response_code(400);
    exit(json_encode([
        "status" => "error",
        "error" => ["code" => 400, "message" => "Thiếu keyCert hoặc time"],
        "data" => null
    ]));
}

// Gán biến và kiểm tra key
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

if (!isset($input['uid'], $input['fcm_token'])) {
    http_response_code(400);
    exit(json_encode(["status" => "error", "message" => "Thiếu uid hoặc fcm_token"]));
}

$uid = $input['uid'];
$fcm_token = $input['fcm_token'];
try{
// Lưu hoặc cập nhật token
$sql = "INSERT INTO user_tokens (uid, fcm_token) VALUES (?, ?) ON DUPLICATE KEY UPDATE fcm_token = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iss", $uid, $fcm_token, $fcm_token);
$stmt->execute();
$stmt->close();

echo json_encode([
    "status" => "success",
    "error" => ["code" => 0, "message" => "Success"],
    "data" => ["fcm_token" => $fcm_token]
]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 500, "message" => "Không thể luu token: " . $e->getMessage()],
        "data" => null
    ]);
}


$conn->close();
?>