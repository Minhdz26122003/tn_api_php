<?php
// User/Notify/read_all.php
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

if (!isset($input['time'], $input['keyCert'])) {
    http_response_code(400);
    exit(json_encode([
        "status"=>"error",
        "error"=>["code"=>400,"message"=>"Thiếu keyCert hoặc time"]
    ]));
}
if (!isValidKey($input['keyCert'], $input['time'])) {
    http_response_code(403);
    exit(json_encode([
        "status"=>"error",
        "error"=>["code"=>403,"message"=>"keyCert không hợp lệ"]
    ]));
}

// Validate uid
if (!isset($input['uid'])) {
    http_response_code(400);
    exit(json_encode([
      "status"=>"error",
      "error"=>["code"=>400,"message"=>"Thiếu uid"]
    ]));
}
$uid = (int)$input['uid'];

// Cập nhật tất cả
$stmt = $conn->prepare("
    UPDATE notifications
    SET status = 1
    WHERE uid = ?
");
$stmt->bind_param("i", $uid);
$ok = $stmt->execute();
$stmt->close();
$conn->close();

if ($ok) {
    echo json_encode([
      "status"=>"success",
      "error"=>["code"=>0,"message"=>"Đã đánh dấu tất cả đã đọc"],
    ]);
} else {
    http_response_code(500);
    echo json_encode([
      "status"=>"error",
      "error"=>["code"=>500,"message"=>"Không thể đánh dấu đọc tất cả"]
    ]);
}
?>