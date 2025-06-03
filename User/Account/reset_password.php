<?php
require '../../vendor/autoload.php';
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token_user.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

$conn = getDBConnection();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $token = $data['token'] ?? '';
    $newPassword = $data['password'] ?? '';

    if (empty($token) || empty($newPassword)) {
        echo json_encode(["status" => "error", "message" => "Thông tin không hợp lệ"]);
        exit;
    }

    $stmt = $conn->prepare("SELECT uid FROM users WHERE reset_token = ? AND reset_expires > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
       
$hashedPassword = password_hash(trim($newPassword), PASSWORD_BCRYPT);

        $stmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE uid = ?");
        $stmt->bind_param("si", $hashedPassword, $user['uid']);
        $stmt->execute();

        echo json_encode(["status" => "success", "message" => "Mật khẩu đã được cập nhật"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Token không hợp lệ hoặc đã hết hạn"]);
    }

    $stmt->close();
    $conn->close();
}
