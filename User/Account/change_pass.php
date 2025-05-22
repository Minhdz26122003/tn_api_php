<?php
require '../../vendor/autoload.php';
require_once "../../Utils/function.php";
require_once "../../Config/connectdb.php"; 
require_once "../../Utils/verify_token_user.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");
header('Content-Type: application/json; charset=utf-8');

checkToken();

$conn = getDBConnection();

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Thiếu dữ liệu yêu cầu hoặc dữ liệu JSON không hợp lệ"],
        "data"   => null
    ]);
    exit;
}

$uid             = $data["uid"] ?? '';
$oldPassword     = $data["oldPassword"] ?? '';
$newPassword     = $data["newPassword"] ?? '';
$confirmPassword = $data["confirmPassword"] ?? '';
$keyCert         = $data["keyCert"] ?? '';
$time            = $data["time"] ?? '';

if (!$uid || !$oldPassword || !$newPassword || !$confirmPassword) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Thiếu thông tin bắt buộc"],
        "data"   => null
    ]);
    exit;
}

// Kiểm tra key
if (!isValidKey($keyCert, $time)) {
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 403, "message" => "KeyCert không hợp lệ"],
        "data" => null
    ]);
    exit;
}
if ($newPassword !== $confirmPassword) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Mật khẩu xác nhận không trùng khớp"],
        "data"   => null
    ]);
    exit;
}


// Lấy mật khẩu hiện tại
$stmt = $conn->prepare("SELECT password FROM users WHERE uid = ?");
$stmt->bind_param("s", $uid);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 404, "message" => "Người dùng không tồn tại"],
        "data"   => null
    ]);
    $stmt->close();
    $conn->close();
    exit;
}

$row = $result->fetch_assoc();
$currentHashedPassword = $row["password"];
$stmt->close();

if (!password_verify($oldPassword, $currentHashedPassword)) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 401, "message" => "Mật khẩu cũ không chính xác"],
        "data"   => null
    ]);
    $conn->close();
    exit;
}

$newHashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

// **CHỖ SỬA**: sử dụng đúng biến $stmtUpdate
$stmtUpdate = $conn->prepare("UPDATE users SET password = ? WHERE uid = ?");
$stmtUpdate->bind_param("ss", $newHashedPassword, $uid);

if ($stmtUpdate->execute()) {
    echo json_encode([
        "status" => "success",
        "error"  => ["code" => 0, "message" => "Mật khẩu đã được thay đổi thành công!"],
        "data"   => ["uid" => $uid]
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 500, "message" => "Cập nhật mật khẩu thất bại"],
        "data"   => null
    ]);
}

$stmtUpdate->close();
$conn->close();
?>
