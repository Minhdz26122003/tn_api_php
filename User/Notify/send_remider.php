<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token_user.php";
require_once __DIR__ . '../../Notify/NotificationService.php';


$conn = getDBConnection();
$notifier = new \Notify\NotificationService();

// Lấy thời gian hiện tại
$now = new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh'));
error_log($now);

// Tính thời điểm 1 giờ và 30 phút sau
$targets = [
    $now->format('Y-m-d H:i:00'),                   
    (clone $now)->modify('+30 minutes')->format('Y-m-d H:i:00'),
    (clone $now)->modify('+1 hour')->format('Y-m-d H:i:00'),
];

// Chuyển mảng thành chuỗi SQL (?, ?, ?)
$placeholders = implode(',', array_fill(0, count($targets), '?'));

$sql = "
  SELECT uid, appointment_date, appointment_time 
  FROM appointment 
  WHERE CONCAT(appointment_date, ' ', appointment_time) IN ($placeholders)
    AND status = 0
";
$stmt = $conn->prepare($sql);
$stmt->bind_param(
    str_repeat('s', count($targets)),
    ...$targets
);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    // Tạo thông điệp
    $when = DateTime::createFromFormat(
        'Y-m-d H:i:s',
        $row['appointment_date'] . ' ' . $row['appointment_time'],
        new DateTimeZone('UTC')
    );
    $diff = $when->getTimestamp() - $now->getTimestamp();
    $unit = $diff === 3600 ? '1 giờ' : '30 phút';
    $title = "Sắp đến lịch hẹn ($unit)";
    $body  = "Bạn có lịch hẹn vào lúc {$row['appointment_time']} ngày {$row['appointment_date']} (còn $unit nữa).";

    // Gửi vừa lưu vào CSDL vừa push
    $notifier->createInDatabase([
        'uid'   => $row['uid'],
        'title' => $title,
        'body'  => $body
    ]);
    $notifier->notifyUser(
        $row['uid'],
        $title,
        $body,
        ['appointment_date' => $row['appointment_date'], 'appointment_time' => $row['appointment_time']]
    );
}

$stmt->close();
$conn->close();
?>