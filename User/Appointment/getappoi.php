<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token_user.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

checkToken();  // xác thực token JWT, thiết lập $uid từ payload

$conn = getDBConnection();

// Nhận time, keyCert, uid
$input = $_POST;
if (empty($input)) {
    $input = json_decode(file_get_contents('php://input'), true);
}
if (!isset($input['time'], $input['keyCert'], $input['uid'])) {
    http_response_code(400);
    exit(json_encode([
      "status"=>"error","error"=>["code"=>400,"message"=>"Thiếu tham số"],"data"=>null
    ]));
}
$time    = $input['time'];
$keyCert = $input['keyCert'];
$uid     = (int)$input['uid'];

if (!isValidKey($keyCert, $time)) {
    http_response_code(403);
    exit(json_encode([
      "status"=>"error","error"=>["code"=>403,"message"=>"KeyCert không hợp lệ"],"data"=>null
    ]));
}

// 1) Lấy danh sách appointment
$sqlAppt = "
  SELECT 
    ap.appointment_id,
    ap.uid,
    ap.car_id,
    cr.license_plate,
    ap.gara_id,
    ct.gara_name,
    ct.gara_address,
    ap.appointment_date,
    ap.appointment_time,
    ap.status,
    ap.description,
    ap.created_at,
    ap.reason
  FROM appointment ap
  LEFT JOIN car cr ON cr.car_id = ap.car_id
  LEFT JOIN center ct ON ct.gara_id = ap.gara_id
  WHERE ap.uid = ? AND ap.status != 5
  ORDER BY ap.appointment_id DESC
";
$stmt = $conn->prepare($sqlAppt);
$stmt->bind_param("i", $uid);
$stmt->execute();
$res = $stmt->get_result();

$appointments = [];
while ($row = $res->fetch_assoc()) {
    $appointments[$row['appointment_id']] = $row;
}
$stmt->close();

// nếu không có lịch hẹn
if (empty($appointments)) {
    echo json_encode([
      "status"=>"success",
      "error"=>["code"=>0,"message"=>"Không có dữ liệu"],
      "items"=>[]
    ]);
    $conn->close();
    exit;
}

// 2) Lấy tất cả service cho những appointment trên
$ids = array_keys($appointments);
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$sqlSvc = "
  SELECT 
    aps.appointment_id,
    s.service_id,
    s.service_name,
    s.service_img,
    s.price,
    s.time AS est_time
  FROM detail_appointment aps
  JOIN service s ON s.service_id = aps.service_id
  WHERE aps.appointment_id IN ($placeholders)
  ORDER BY aps.appointment_id, s.service_id
";
$stmt = $conn->prepare($sqlSvc);

// ràng buộc tham số động
$types = str_repeat('i', count($ids));
$stmt->bind_param($types, ...$ids);
$stmt->execute();
$res2 = $stmt->get_result();

// gán vào từng appointment
while ($r = $res2->fetch_assoc()) {
    $aid = $r['appointment_id'];
    if (!isset($appointments[$aid]['services'])) {
        $appointments[$aid]['services'] = [];
    }
    $appointments[$aid]['services'][] = [
      'service_id'   => $r['service_id'],
      'service_name' => $r['service_name'],
      'service_img'  => $r['service_img'],
      'price'        => $r['price'],
      'time'         => $r['est_time'],
    ];
}
$stmt->close();
$conn->close();

// 3) Trả về kết quả
echo json_encode([
  "status"=>"success",
  "error"=>["code"=>0,"message"=>"Success"],
  "items"=>array_values($appointments)
]);
?>