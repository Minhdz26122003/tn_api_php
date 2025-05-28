<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token_user.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");
checkToken(); 

$conn = getDBConnection();

$input = $_POST;
if (empty($input)) {
    $input = json_decode(file_get_contents('php://input'), true);
}

if (!isset($input['time'], $input['keyCert'], $input['appointment_date'], $input['appointment_time'])) {
    http_response_code(400);
    exit(json_encode([
      "status" => "error", 
      "error" => ["code" => 400, "message" => "Thiếu tham số bắt buộc (time, keyCert, appointment_date, appointment_time)"],
      "data" => null
    ]));
}

$time             = $input['time'];
$keyCert          = $input['keyCert'];
$appointment_date = $input['appointment_date'];
$appointment_time = $input['appointment_time'];

if (!isValidKey($keyCert, $time)) {
    http_response_code(403);
    exit(json_encode([
      "status" => "error", 
      "error" => ["code" => 403, "message" => "KeyCert không hợp lệ"],
      "data" => null
    ]));
}

try {
    // Kiểm tra xem có lịch hẹn nào đã tồn tại với ngày và giờ đã cho không (bất kể car_id nào)
    $sql = "SELECT COUNT(*) AS count FROM appointment WHERE appointment_date = ? AND appointment_time = ? AND status IN (0, 1, 2, 3, 4, 5, 6)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $appointment_date, $appointment_time); 
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $count = $row['count'];

    echo json_encode([
        "status" => "success",
        "error" => ["code" => 0, "message" => "Thành công"],
        "is_booked" => $count > 0 
    ]);

} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    exit(json_encode([
      "status" => "error", 
      "error" => ["code" => 500, "message" => "Lỗi truy vấn cơ sở dữ liệu: " . $e->getMessage()],
      "data" => null
    ]));
} finally {
    $conn->close();
}
?>