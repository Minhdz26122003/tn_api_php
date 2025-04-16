<?php
require '../../vendor/autoload.php';
require_once "../../Utils/function.php";
require_once "../../Config/connectdb.php"; 

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

// Mặc định phản hồi thành công
$response = [
    "status" => "success",
    "error"  => ["code" => 0, "message" => "Success"],
    "data"   => null
];
$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    $response = [
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Thiếu dữ liệu yêu cầu hoặc dữ liệu JSON không hợp lệ"],
        "data"   => null
    ];
    echo json_encode($response);
    exit;
}

$email       = $data['email']       ?? '';
$otp         = $data['otp']         ?? '';
$newPassword = $data['newPassword'] ?? '';
$keyCert     = $data['keyCert']     ?? '';
$time        = $data['time']        ?? '';

if (!$email || !$otp || !$newPassword || !$keyCert || !$time) {
    $response = [
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Thiếu dữ liệu yêu cầu"],
        "data"   => null
    ];
    echo json_encode($response);
    exit;
}

// Xác thực keyCert
if (!isValidKey($keyCert, $time)) {
    $response = [
        "status" => "error",
        "error"  => ["code" => 403, "message" => "Xác thực thất bại"],
        "data"   => null
    ];
    echo json_encode($response);
    exit;
}



// Kiểm tra định dạng email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $response = [
        "status" => "error",
        "error"  => ["code" => 422, "message" => "Email không hợp lệ"],
        "data"   => null
    ];
    echo json_encode($response);
    exit;
}


$conn = getDBConnection();

// Lấy OTP mới nhất trong bảng password_reset
$stmt = $conn->prepare("SELECT otp, expires_at FROM password_reset WHERE email = ? ORDER BY expires_at DESC LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if (!$row) {
    $response = [
        "status" => "error",
        "error"  => ["code" => 404, "message" => "OTP không tồn tại hoặc đã hết hạn"],
        "data"   => null
    ];
    echo json_encode($response);
    $conn->close();
    exit;
}

$storedOtp = $row["otp"];
$expiry    = strtotime($row["expires_at"]);


if (!hash_equals($storedOtp, $otp) || time() > $expiry) {
    $response = [
        "status" => "error",
        "error"  => ["code" => 401, "message" => "OTP không hợp lệ hoặc đã hết hạn"],
        "data"   => null
    ];
    echo json_encode($response);
    $conn->close();
    exit;
}

$hashedPassword = password_hash(trim($newPassword), PASSWORD_BCRYPT);

// Cập nhật mật khẩu và xóa OTP trong cùng một giao dịch
$conn->begin_transaction();
try {
    
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
    $stmt->bind_param("ss", $hashedPassword, $email);
    $stmt->execute();
    $stmt->close();

    // Xóa OTP khỏi bảng password_reset để tránh tái sử dụng
    $stmt = $conn->prepare("DELETE FROM password_reset WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    $response = [
        "status" => "success",
        "error"  => ["code" => 0, "message" => "Mật khẩu đã được thay đổi thành công!"],
        "data"   => ["email" => $email]
    ];
} catch (Exception $e) {
    $conn->rollback();
    $response = [
        "status" => "error",
        "error"  => ["code" => 500, "message" => "Lỗi cập nhật mật khẩu"],
        "data"   => null
    ];
}

$conn->close();
echo json_encode($response);
?>
