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

// Kiểm tra keyCert và time có gửi lên không?
if (!isset($input['time'], $input['keyCert'])) {
    http_response_code(400);
    exit(json_encode([
        "status"=>"error",
        "error"=>["code"=>400,"message"=>"Thiếu keyCert hoặc time"],
        "data"=>null
    ]));
}

// Gán biến và kiểm tra key
$time    = $input['time'];
$keyCert = $input['keyCert'];

if (!isValidKey($keyCert, $time)) {
    http_response_code(403);
    exit(json_encode([
        "status"=>"error",
        "error"=>["code"=>403,"message"=>"keyCert không hợp lệ hoặc hết hạn"],
        "data"=>null
    ]));
}

// Kiểm tra các tham số bắt buộc
if (!isset($input['uid'], $input['car_id'], $input['gara_id'], $input['appointment_date'], $input['description'], $input['appointment_time'], $input['status'], $input['reason']) || !is_array($input['serviceIds'])) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Thiếu tham số hoặc serviceIds không hợp lệ"],
        "data"   => null
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
$reason =$input['reason'] ?? null;
$serviceIds = $input['serviceIds'];

if (!isValidKey($keyCert, $time)) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 403, "message" => "keyCert không hợp lệ"],
        "data"   => null
    ]);
    exit;
}

$dtDate = DateTime::createFromFormat('Y-m-d', $appointment_date);
$dtTime = DateTime::createFromFormat('H:i', $appointment_time);

if (!$dtDate || !$dtTime) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Định dạng ngày hoặc giờ không hợp lệ"],
        "data"   => null
    ]);
    exit;
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