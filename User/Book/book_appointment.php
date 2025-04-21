<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/responseHelper.php";
require_once "../../Utils/function.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

$conn = getDBConnection();

$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
}
// Kiểm tra keycert và time
if (!isset($input['keycert']) || !isset($input['time'])) {
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 400, "message" => "Thiếu keycert hoặc time"],
        "input" => null
    ]);
    exit;
}

if (!isValidKey($keyCert, $time)) {
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 401, "message" => "Keycert không hợp lệ hoặc hết hạn"],
        "data" => null
    ]);
    exit;
}
// Kiểm tra các tham số bắt buộc
if (!isset($input['email'], $input['carId'], $input['centerId'], $input['date'], $input['session'], $input['timeStart'], $input['serviceIds']) || !is_array($input['serviceIds'])) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Thiếu tham số hoặc serviceIds không hợp lệ"],
        "data"   => null
    ]);
    exit;
}

$time = $input['time'];
$keyCert = $input['keyCert'];
$email = $input['email'];
$carId = $input['carId'];
$centerId = $input['centerId'];
$date = $input['date'];
$session = $input['session'];
$timeStart = $input['timeStart'];
$description = $input['description'] ?? '';
$status = 'pending';
$serviceIds = $input['serviceIds'];

if (!isValidKey($keyCert, $time)) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 403, "message" => "KeyCert không hợp lệ"],
        "data"   => null
    ]);
    exit;
}

if (!DateTime::createFromFormat('Y-m-d', $date) || !DateTime::createFromFormat('H:i', $timeStart)) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Định dạng ngày hoặc giờ không hợp lệ"],
        "data"   => null
    ]);
    exit;
}

$appointmentTime = "$date $timeStart:00";

// Lấy uid từ email
$sqlUid = "SELECT uid FROM users WHERE email = ?";
$stmtUid = $conn->prepare($sqlUid);
$stmtUid->bind_param("s", $email);
$stmtUid->execute();
$resultUid = $stmtUid->get_result();

if ($resultUid->num_rows === 0) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 404, "message" => "Người dùng không tồn tại"],
        "data"   => null
    ]);
    exit;
}

$uid = $resultUid->fetch_assoc()['uid']; 
$stmtUid->close();

// Bắt đầu giao dịch
$conn->begin_transaction();

try {
    // Chèn dữ liệu vào bảng appointments
    $sql = "INSERT INTO appointments (uid, car_id, gara_id, appointment_date, appointment_time, description, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiisssss", $uid, $carId, $centerId, $date, $appointmentTime, $description, $status);
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

    
    $conn->commit();

    echo json_encode([
        "status" => "success",
        "error"  => ["code" => 0, "message" => "Success"],
        "items"  => ["appointment_id" => $appointmentId]
    ]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 500, "message" => "Không thể đặt lịch: " . $e->getMessage()],
        "data"   => null
    ]);
}

$conn->close();
?>