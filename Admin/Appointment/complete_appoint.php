<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../../vendor/autoload.php'; 


require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php"; // Giả sử hàm generateOTP() hoặc các hàm tiện ích khác có thể cần
require_once "../../Utils/verify_token.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");
date_default_timezone_set('Asia/Ho_Chi_Minh'); 

$conn = getDBConnection();

if ($conn->connect_error) {
    
    echo json_encode([
        "success" => false,
        "message" => "Kết nối thất bại: " . $conn->connect_error
    ]);
    exit();
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

/**
 * Hàm gửi email thông báo hoàn thành lịch hẹn
 */
function sendCompletionNotification($recipientEmail, $appointmentId, $conn_mailer_config) {
    $subject = "AppWeCarAuto - Lịch hẹn #" . $appointmentId . " đã hoàn thành";
    $messageBody = "Chào bạn,<br><br>";
    $messageBody .= "Lịch hẹn sửa chữa có mã <b>#{$appointmentId}</b> của bạn đã được hoàn thành.<br><br>";
    $messageBody .= "Xin mời bạn đến nơi để lấy xe của mình.<br><br>";
    $messageBody .= "Cảm ơn bạn đã sử dụng dịch vụ của AppWeCarAuto.<br><br>";
    $messageBody .= "Trân trọng,<br>Đội ngũ AppWeCarAuto";

    $mail = new PHPMailer(true);
    try {
        $mail->CharSet = 'UTF-8'; // Hỗ trợ tiếng Việt
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';                     // Máy chủ SMTP của bạn
        $mail->SMTPAuth   = true;
        $mail->Username   = "nguyenngocminh261203@gmail.com";    // Email gửi
        $mail->Password   = "hwhl rgxs ufii hbnv";              // Mật khẩu email gửi hoặc App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom("nguyenngocminh261203@gmail.com", 'AppWeCarAuto'); // Tên người gửi và email
        $mail->addAddress($recipientEmail);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $messageBody;

        if ($mail->send()) {
            return true;
        } else {
            error_log("Mailer Error: " . $mail->ErrorInfo);
            return false;
        }
    } catch (Exception $e) {
       error_log("Mailer Exception: " . $e->getMessage());
        return false;
    }
}


$data = json_decode(file_get_contents("php://input"), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($data['appointment_id'])) {
        $appointment_id = $data['appointment_id'];
        $emailSentStatus = false; 
        $user_email = null;

     
        $conn->begin_transaction();

        try {
            // Cập nhật trạng thái lịch hẹn
            $query_update = "UPDATE appointment SET status = 4 WHERE appointment_id = ?";
            $stmt_update = $conn->prepare($query_update);
            if (!$stmt_update) {
                throw new Exception("Lỗi chuẩn bị câu lệnh cập nhật: " . $conn->error);
            }
            $stmt_update->bind_param('i', $appointment_id);

            if (!$stmt_update->execute()) {
                throw new Exception("Lỗi thực thi cập nhật trạng thái: " . $stmt_update->error);
            }
            $stmt_update->close();

            // Lấy email người dùng từ appointment_id
           
            
            // Ví dụ: Nếu bảng appointment có user_id và bảng users có email
            $query_email = "SELECT u.email 
                            FROM users u
                            JOIN appointment a ON u.uid = a.uid 
                            WHERE a.appointment_id = ?";
            
            $stmt_email = $conn->prepare($query_email);
            if (!$stmt_email) {
                 throw new Exception("Lỗi chuẩn bị câu lệnh lấy email: " . $conn->error);
            }
            $stmt_email->bind_param('i', $appointment_id);
            $stmt_email->execute();
            $result_email = $stmt_email->get_result();

            if ($row = $result_email->fetch_assoc()) {
                $user_email = $row['email'];
            }
            $stmt_email->close();

            if ($user_email && filter_var($user_email, FILTER_VALIDATE_EMAIL)) {
                // Gửi email thông báo
                if (sendCompletionNotification($user_email, $appointment_id, null)) {
                    $emailSentStatus = true;
                }
            } else if ($user_email) {
                // Email lấy được không hợp lệ, không gửi nhưng vẫn coi là cập nhật thành công
                error_log("Invalid email format for appointment ID {$appointment_id}: {$user_email}");
            } else {
                 // Không tìm thấy email, không gửi nhưng vẫn coi là cập nhật thành công
                error_log("User email not found for appointment ID {$appointment_id}");
            }

            // Nếu mọi thứ thành công, commit transaction
            $conn->commit();

            $responseMessage = 'Lịch hẹn đã được hoàn thành.';
            if ($user_email) {
                $responseMessage .= ($emailSentStatus ? ' Email thông báo đã được gửi.' : ' Gửi email thông báo thất bại.');
            } else {
                $responseMessage .= ' Không tìm thấy email người dùng để gửi thông báo.';
            }
            echo json_encode(['success' => true, 'message' => $responseMessage, 'email_sent' => $emailSentStatus]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Đã xảy ra lỗi: ' . $e->getMessage()]);
        }

    } else {
        echo json_encode(['success' => false, 'message' => 'Thiếu thông tin appointment_id.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Phương thức không được hỗ trợ.']);
}

$conn->close();
?>