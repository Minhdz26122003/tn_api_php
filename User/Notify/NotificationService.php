<?php
namespace Notify;

require_once __DIR__ . '/../../Config/connectdb.php';

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;

class NotificationService
{
    protected $messaging;
    protected $conn;

    public function __construct()
    {
        $this->conn = \getDBConnection();

        // Chú ý đường dẫn tới file JSON service account
        $serviceAccountPath = __DIR__ . '/../../demo1-4b8c1-firebase-adminsdk-fbsvc-30bcd01c3c.json';
        $factory = (new Factory)
            ->withServiceAccount($serviceAccountPath);

        $this->messaging = $factory->createMessaging();
    }

    public function notifyUser(int $uid, string $title, string $body): bool
    {
        $token = $this->getTokenByUserId($uid);
        if (!$token) {
            error_log("No FCM token for uid=$uid");
            return false;
        }
        return $this->sendFCM($token, $title, $body);
    }

    protected function getTokenByUserId(int $uid): ?string
    {
        $stmt = $this->conn->prepare("SELECT fcm_token FROM user_tokens WHERE uid = ?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['fcm_token'] ?? null;
    }

    protected function sendFCM(string $token, string $title, string $body): bool
    {
        $message = CloudMessage::withTarget('token', $token)
            ->withNotification(['title' => $title, 'body' => $body]);
          

        try {
            $this->messaging->send($message);
            error_log("FCM sent to $token");
            return true;
        } catch (\Throwable $e) {
            error_log("FCM send error: " . $e->getMessage());
            return false;
        }
    }

    public function createInDatabase(array $payload): bool
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO notifications (uid, title, body, status, time_created)
             VALUES (?, ?, ?, 0, NOW())"
        );
        $stmt->bind_param(
            "iss",
            $payload['uid'],
            $payload['title'],
            $payload['body']
        );
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}
