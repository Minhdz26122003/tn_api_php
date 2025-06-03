<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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

function saveOTPToDB($email, $otp, $conn) {
    // Kiểm tra email có tồn tại trong bảng users
    $stmt = $conn->prepare("SELECT email FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

   
    if ($result->num_rows == 0) {
        return [
            "data" => null,
            "error" => ["code" => 404, "message" => "Email không tồn tại trong hệ thống"]
        ];
    }

    
    $expires_at = date("Y-m-d H:i:s", time() + 300);

    
    $stmt = $conn->prepare("DELETE FROM password_reset WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->close();

    
    $stmt = $conn->prepare("INSERT INTO password_reset (email, otp, expires_at) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $email, $otp, $expires_at);

    if ($stmt->execute()) {
        $stmt->close();
        return [
            "data" => [
                "email" => $email,
                "otp" => $otp,
                "expires_at" => $expires_at
            ],
            "error" => ["code" => 0, "message" => "OTP đã được gửi thành công!"]
        ];
    } else {
        $stmt->close();
        return [
            "data" => null,
            "error" => ["code" => 500, "message" => "Lỗi khi lưu OTP vào database!"]
        ];
    }
}

/**
 * Hàm gửi OTP qua email (trả về mảng JSON, không echo)
 */
function sendOTP($email, $conn) {
    
    $otp = generateOTP();

    $subject = "System AppHM - Confirm OTP";
    $message = "Your OTP code is: <b>$otp</b>. The code is valid in 5 minutes.";

    $mail = new PHPMailer(true);
    try {

        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = "nguyenngocminh261203@gmail.com"; 
        $mail->Password   = "hwhl rgxs ufii hbnv"; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

    
        $mail->setFrom("nguyenngocminh261203@gmail.com", 'AppWeCarAuto');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $message;

        if ($mail->send()) {
            return saveOTPToDB($email, $otp, $conn);
        } else {
            return [
                "data" => null,
                "error" => ["code" => 1, "message" => "Lỗi gửi OTP."]
            ];
        }
    } catch (Exception $e) {
        return [
            "data" => null,
            "error" => ["code" => 1, "message" => "Lỗi: " . $mail->ErrorInfo]
        ];
    }
}


// ====================== Xử lý chính =========================

$conn = getDBConnection();
$conn->query("SET time_zone = '+07:00'");
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    $response = [
        "data" => null,
        "error" => ["code" => 4, "message" => "Dữ liệu JSON không hợp lệ!"]
    ];
    echo json_encode($response);
    $conn->close();
    exit;
}

if (!isset($data["keyCert"], $data["time"]) || !isValidKey($data["keyCert"], $data["time"])) {
    $response = [
        "data" => null,
        "error" => ["code" => 5, "message" => "Xác thực thất bại!"]
    ];
    echo json_encode($response);
    $conn->close();
    exit;
}
if (!isset($data["email"]) || !filter_var($data["email"], FILTER_VALIDATE_EMAIL)) {
    $response = [
        "data" => null,
        "error" => ["code" => 2, "message" => "Email không hợp lệ!"]
    ];
    echo json_encode($response);
    $conn->close();
    exit;
}

// Tất cả hợp lệ => Gửi OTP
$response = sendOTP($data["email"], $conn);


$conn->close();
echo json_encode($response);