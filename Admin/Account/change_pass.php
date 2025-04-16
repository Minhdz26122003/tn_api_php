<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

$conn = getDBConnection();
if ($conn->connect_error) { 
    die("Kết nối thất bại: " . $conn->connect_error);
}

// Lấy token từ header
$headers = getallheaders();
$token = $headers["Authorization"] ?? "";

// Xác thực token
if (!verifyToken($token)) {
    echo json_encode([
        "success" => false,
        "message" => "Token không hợp lệ hoặc đã hết hạn"
    ]);
    $conn->close();
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['data'])) {
    echo json_encode(["success" => false, "message" => "Dữ liệu không hợp lệ"]);
    exit();
}

// Giải mã Base64 với UTF-8
$decodedJson = urldecode(base64_decode($input['data']));
$decodedData = json_decode($decodedJson, true);

if (!$decodedData) {
    echo json_encode(["success" => false, "message" => "Lỗi giải mã dữ liệu"]);
    exit();
}

$uid = $decodedData['uid'] ?? null;
$currentPassword = $decodedData['currentPassword'] ?? null;
$newPassword = $decodedData['newPassword'] ?? null;

if (!$uid || !$currentPassword || !$newPassword) {
    echo json_encode([
        "success" => false,
        "message" => "Thiếu thông tin: uid, currentPassword hoặc newPassword"
    ]);
    $conn->close();
    exit();
}

if (strlen($newPassword) < 6) {
    echo json_encode([
        "success" => false,
        "message" => "Mật khẩu mới phải ít nhất 6 ký tự"
    ]);
    $conn->close();
    exit();
}

$query = "SELECT password FROM users WHERE uid = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $uid);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode([
        "success" => false,
        "message" => "Người dùng không tồn tại"
    ]);
    $stmt->close();
    $conn->close();
    exit();
}

$user = $result->fetch_assoc();
$storedPassword = $user['password'];

if (!password_verify($currentPassword, $storedPassword)) {
    echo json_encode([
        "success" => false,
        "message" => "Mật khẩu cũ không đúng"
    ]);
    $stmt->close();
    $conn->close();
    exit();
}

$hashedNewPassword = password_hash($newPassword, PASSWORD_DEFAULT);

$query = "UPDATE users SET password = ? WHERE uid = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('si', $hashedNewPassword, $uid);

if ($stmt->execute()) {
    echo json_encode([
        "success" => true,
        "message" => "Đổi mật khẩu thành công"
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Đổi mật khẩu không thành công: " . $conn->error
    ]);
}

$stmt->close();
$conn->close();
?>