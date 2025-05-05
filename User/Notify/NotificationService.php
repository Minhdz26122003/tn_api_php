<?php
namespace Notify;

require_once __DIR__ . '/../../Config/connectdb.php';
require_once __DIR__ . '/../../Utils/function.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class NotificationService
{
    protected $messaging;
    protected $conn;

    public function __construct()
    {
        // Khởi tạo kết nối database
        $this->conn = \getDBConnection();

        // Đường dẫn tới file Service Account JSON
        $serviceAccountPath = __DIR__ . '/../../demo1-4b8c1-firebase-adminsdk-fbsvc-30bcd01c3c.json';
        $factory = (new Factory)
            ->withServiceAccount($serviceAccountPath);
        $this->messaging = $factory->createMessaging();
    }

    /**
     * Gửi push notification qua FCM
     */
    public function notifyUser(int $uid, string $title, string $body, array $data = []): bool
    {
        $token = $this->getTokenByUserId($uid);
        if (!$token) {
            error_log("No FCM token for uid={$uid}");
            return false;
        }
        return $this->sendFCM($token, $title, $body, $data);
    }

    /**
     * Lưu notification vào database
     */
    public function createInDatabase(array $payload): bool
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO notifications (uid, title, body, status, time_created) VALUES (?, ?, ?, 0, NOW())"
        );
        $stmt->bind_param("iss", $payload['uid'], $payload['title'], $payload['body']);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * Gửi email qua SMTP (PHPMailer)
     */
    public function sendEmail(string $toEmail, string $subject, string $htmlBody): array
    {
        $mail = new PHPMailer(true);
        try {
            // Cấu hình SMTP
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'nguyenngocminh261203@gmail.com';
            $mail->Password   = 'hwhl rgxs ufii hbnv';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            // Thiết lập người gửi và người nhận
            $mail->setFrom('nguyenngocminh261203@gmail.com', 'AppWeCarAuto');
            $mail->addAddress($toEmail);

            // Thiết lập nội dung email
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;

            $mail->send();
            return [
                'data' => ['email' => $toEmail],
                'error'=> ['code'=>0, 'message'=>'Email sent successfully']
            ];
        } catch (Exception $e) {
            return [
                'data' => null,
                'error'=> ['code'=>1, 'message'=>'Mailer Error: ' . $mail->ErrorInfo]
            ];
        }
    }

    /**
     * Lấy FCM token từ bảng user_tokens
     */
    protected function getTokenByUserId(int $uid): ?string
    {
        $stmt = $this->conn->prepare("SELECT fcm_token FROM user_tokens WHERE uid = ?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['fcm_token'] ?? null;
    }

    /**
     * Thực thi gửi FCM
     */
    protected function sendFCM(string $token, string $title, string $body, array $data = []): bool
    {
        $message = CloudMessage::withTarget('token', $token)
            ->withNotification(['title'=>$title, 'body'=>$body])
            ->withData($data);
        try {
            $this->messaging->send($message);
            error_log("FCM sent to {$token}");
            return true;
        } catch (\Throwable $e) {
            error_log('FCM send error: ' . $e->getMessage());
            return false;
        }
    }
}
