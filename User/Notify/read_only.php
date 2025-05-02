<?php
// User/Notify/read_only.php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token_user.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

checkToken();
$conn = getDBConnection();

// Lấy input
$input = $_POST;
if (empty($input)) {
    $input = json_decode(file_get_contents('php://input'), true);
}

// Validate keyCert/time
if (!isset($input['time'], $input['keyCert'])) {
    http_response_code(400);
    exit(json_encode([
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Thiếu keyCert hoặc time"]
    ]));
}
if (!isValidKey($input['keyCert'], $input['time'])) {
    http_response_code(403);
    exit(json_encode([
        "status" => "error",
        "error"  => ["code" => 403, "message" => "keyCert không hợp lệ"]
    ]));
}

// Validate uid và noti_id (id thông báo)
if (!isset($input['uid'], $input['noti_id'])) {
    http_response_code(400);
    exit(json_encode([
      "status"=>"error",
      "error"=>["code"=>400,"message"=>"Thiếu uid hoặc noti_id"]
    ]));
}
$uid  = (int)$input['uid'];
$noti_id = $conn->real_escape_string($input['noti_id']);

// Cập nhật
$stmt = $conn->prepare("
    UPDATE notifications
    SET status = 1
    WHERE uid = ? AND noti_id = ?
");
$stmt->bind_param("ii", $uid, $noti_id);
$ok = $stmt->execute();
$stmt->close();
$conn->close();

if ($ok) {
    echo json_encode([
      "status"=>"success",
      "error"=>["code"=>0,"message"=>"Đã đánh dấu đã đọc"],
    ]);
} else {
    http_response_code(500);
    echo json_encode([
      "status"=>"error",
      "error"=>["code"=>500,"message"=>"Không thể đánh dấu đọc"]
    ]);
}
?>