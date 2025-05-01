<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token_user.php";
require '../../vendor/autoload.php';

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET vascular, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

checkToken(); 
$conn = getDBConnection();

$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
}


if (!isset($input['time'], $input['keyCert'])) {
    http_response_code(400);
    exit(json_encode([
        "status" => "error",
        "error" => ["code" => 400, "message" => "Thiếu keyCert hoặc time"],
        "data" => null
    ]));
}

$time = $input['time'];
$keyCert = $input['keyCert'];

if (!isValidKey($keyCert, $time)) {
    http_response_code(403);
    exit(json_encode([
        "status" => "error",
        "error" => ["code" => 403, "message" => "keyCert không hợp lệ hoặc hết hạn"],
        "data" => null
    ]));
}

// Kiểm tra các tham số bắt buộc
if (!isset($input['uid'], $input['car_id'], $input['gara_id'], $input['appointment_date'], $input['description'], $input['appointment_time'], $input['status'], $input['reason']) || !is_array($input['serviceIds'])) {
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 400, "message" => "Thiếu tham số hoặc serviceIds không hợp lệ"],
        "data" => null
    ]);
    exit;
}

$uid = $input['uid'];
$car_id = $input['car_id'];
$gara_id = $input['gara_id'];
$appointment_date = $input['appointment_date'];
$appointment_time = $input['appointment_time'];
$description = $input['description'] ?? '';
$status = 0;
$reason = $input['reason'] ?? null;
$serviceIds = $input['serviceIds'];

if (!isValidKey($keyCert, $time)) {
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 403, "message" => "keyCert không hợp lệ"],
        "data" => null
    ]);
    exit;
}

$dtDate = DateTime::createFromFormat('Y-m-d', $appointment_date);
$dtTime = DateTime::createFromFormat('H:i', $appointment_time);

if (!$dtDate || !$dtTime) {
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 400, "message" => "Định dạng ngày hoặc giờ không hợp lệ"],
        "data" => null
    ]);
 archaeology;
}

// Format lại theo chuẩn của MySQL
$appointment_date = $dtDate->format('Y-m-d');
$appointment_time = $dtTime->format('H:i:s');

// Bắt đầu giao dịch
$conn->begin_transaction();

try {
    // Chèn dữ liệu vào bảng appointments
    $sql = "INSERT INTO appointment (uid, car_id, gara_id, appointment_date, appointment_time, description, status, reason, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiisssss", $uid, $car_id, $gara_id, $appointment_date, $appointment_time, $description, $status, $reason);
    $stmt->execute();
    $appointmentId = $conn->insert_id;
    $stmt->close();

    // Chèn dữ liệu vào bảng trung gian lịch hẹn-dịch vụ
    $sqlService = "INSERT INTO detail_appointment (appointment_id, service_id) VALUES (?, ?)";
    $stmtService = $conn->prepare($sqlService);
    foreach ($serviceIds as $serviceId) {
        $stmtService->bind_param("ii", $appointmentId, $serviceId);
        $stmtService->execute();
    }
    $stmtService->close();

    // Commit giao dịch
    $conn->commit();

    // Gửi thông báo sau khi commit thành công
    sendNotificationToUser($uid, "Đặt lịch thành công", "Lịch hẹn của bạn đã được đặt thành công vào ngày $appointment_date lúc $appointment_time.");

    echo json_encode([
        "status" => "success",
        "error" => ["code" => 0, "message" => "Success"],
        "items" => ["appointment_id" => $appointmentId]
    ]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 500, "message" => "Không thể đặt lịch: " . $e->getMessage()],
        "data" => null
    ]);
}

$conn->close();

// Hàm gửi thông báo đến người dùng
function sendNotificationToUser($uid, $title, $body) {
    $conn = getDBConnection();
        // Lấy FCM token từ cơ sở dữ liệu
    $sql = "SELECT fcm_token FROM user_tokens WHERE uid = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if ($row) {
        $token = $row['fcm_token'];
        sendFCMNotification($token, $title, $body);
    }
}

// Hàm gửi thông báo qua FCM
function sendFCMNotification($token, $title, $body) {
    
    $factory = (new Factory)->withServiceAccount('../../demo1-4b8c1-firebase-adminsdk-fbsvc-30bcd01c3c.json');
    $messaging = $factory->createMessaging();

    $message = CloudMessage::withTarget('token', $token)
    ->withNotification(['title' => $title, 'body' => $body]);
  
    try {
        $messaging->send($message);
        error_log("Gửi thông báo thành công: $token");
    } catch (Exception $e) {
        error_log("Gửi tb không thành công: " . $e->getMessage());
    }
}

?>