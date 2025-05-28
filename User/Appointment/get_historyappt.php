<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token_user.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
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

if (!isset($input['time'], $input['keyCert'], $input['uid'])) {
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 400, "message" => "Thiếu tham số"],
        "data" => null
    ]);
    exit;
}

$time = $input['time'];
$keyCert = $input['keyCert'];
$uid = $input['uid'];

if (!isValidKey($keyCert, $time)) {
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 403, "message" => "KeyCert không hợp lệ"],
        "data" => null
    ]);
    exit;
}

// Truy vấn danh sách lịch hẹn
// Điều chỉnh lại câu SQL để lấy được thông tin service_name và description của service
$sql = "
SELECT 
    ap.appointment_id,
    ap.uid,
    tk.username,
    ap.car_id,
    cr.license_plate,
    ap.gara_id,
    tt.gara_name,
    tt.gara_address,
    ap.appointment_date, 
    ap.appointment_time,
    ap.status,
    ap.description,
    ap.created_at,
    ap.reason,
    GROUP_CONCAT(sv.service_name SEPARATOR ', ') AS service_names,
    GROUP_CONCAT(sv.description SEPARATOR '|||') AS service_descriptions,
    GROUP_CONCAT(sv.price SEPARATOR ', ') AS service_prices,
    GROUP_CONCAT(sv.time SEPARATOR ', ') AS service_times

FROM 
    appointment ap 
LEFT JOIN 
    gara tt  ON tt.gara_id = ap.gara_id
LEFT JOIN 
    car cr ON cr.car_id = ap.car_id
LEFT JOIN
    users tk ON tk.uid = ap.uid
LEFT JOIN
    appointment_service aps ON aps.appointment_id = ap.appointment_id
LEFT JOIN
    service sv ON sv.service_id = aps.service_id
WHERE ap.uid = ? AND ap.status IN (3, 7, 8) 
GROUP BY ap.appointment_id
ORDER BY 
    ap.appointment_id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $uid);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }

    echo json_encode([
        "status" => "success",
        "error" => ["code" => 0, "message" => "Success"],
        "items" => $items
    ]);
} else {
    echo json_encode([
        "status" => "success",
        "error" => ["code" => 0, "message" => "Không có dữ liệu"],
        "items" => []
    ]);
}

$stmt->close();
$conn->close();
?>