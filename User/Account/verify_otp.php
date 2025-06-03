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

date_default_timezone_set('Asia/Ho_Chi_Minh');
// define("SECRET_KEY", "minh8386");

$response = [
    "error" => ["code" => 0, "message" => "OTP hợp lệ!"],
    "data"  => null
];


$data = json_decode(file_get_contents("php://input"), true);

// Kiểm tra JSON hợp lệ
if (!$data) {
    $response = [
        "error" => ["code" => 4, "message" => "Dữ liệu JSON không hợp lệ!"],
        "data"  => null
    ];
    echo json_encode($response);
    exit;
}

// Kiểm tra keyCert
if (!isset($data["keyCert"], $data["time"]) || !isValidKey($data["keyCert"], $data["time"])) {
    $response = [
        "error" => ["code" => 5, "message" => "Xác thực thất bại!"],
        "data"  => null
    ];
    echo json_encode($response);
    exit;
}

// Kiểm tra thời gian (chỉ chấp nhận request trong 5 phút)
if (abs(time() - strtotime($data["time"])) > 300) {
    $response = [
        "error" => ["code" => 6, "message" => "Thời gian yêu cầu không hợp lệ!"],
        "data"  => null
    ];
    echo json_encode($response);
    exit;
}

// Kiểm tra email
if (!isset($data["email"]) || !filter_var($data["email"], FILTER_VALIDATE_EMAIL)) {
    $response = [
        "error" => ["code" => 2, "message" => "Email không hợp lệ!"],
        "data"  => null
    ];
    echo json_encode($response);
    exit;
}

// Kiểm tra OTP
if (!isset($data["otp"]) || empty($data["otp"])) {
    $response = [
        "error" => ["code" => 3, "message" => "Thiếu mã OTP!"],
        "data"  => null
    ];
    echo json_encode($response);
    exit;
}

// ============== XỬ LÝ CHÍNH ==============


$conn = getDBConnection();

$email = $data["email"];
$otp   = $data["otp"];

$stmt = $conn->prepare("SELECT otp, expires_at 
                        FROM password_reset 
                        WHERE email = ? 
                        ORDER BY idpr DESC LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();


if ($result->num_rows === 0) {
    $response = [
        "error" => ["code" => 7, "message" => "OTP không tồn tại hoặc đã hết hạn!"],
        "data"  => null
    ];
} else {
    $row       = $result->fetch_assoc();
    $storedOtp = $row["otp"];
    $expiresAt = strtotime($row["expires_at"]);

    // So sánh OTP
    if (!hash_equals($storedOtp, $otp)) {
        $response = [
            "error" => ["code" => 8, "message" => "OTP không hợp lệ!"],
            "data"  => null
        ];
    } elseif (time() > $expiresAt) {
        $response = [
            "error" => ["code" => 9, "message" => "OTP đã hết hạn!"],
            "data"  => null
        ];
    } else {
        // // OTP hợp lệ => Xóa OTP
        // $stmt = $conn->prepare("DELETE FROM password_reset WHERE email = ?");
        // $stmt->bind_param("s", $email);
        // $stmt->execute();
        // $stmt->close();

        $response = [
            "error" => ["code" => 0, "message" => "OTP xác thực thành công!"],
            "data"  => ["email" => $email]
        ];
    }
}

$conn->close();
echo json_encode($response);
