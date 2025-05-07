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

// Lấy danh sách appointment
$sqlAppt = "
  SELECT 
    ap.appointment_id,
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
  WHERE ap.uid = ?
  ORDER BY ap.appointment_id DESC
";
$stmt = $conn->prepare($sqlAppt);
$stmt->bind_param("i", $uid);
$stmt->execute();
$res = $stmt->get_result();

$appointments = [];
while ($row = $res->fetch_assoc()) {
    // khởi tạo mảng services rỗng
    $row['services'] = [];
    $appointments[$row['appointment_id']] = $row;
}
$stmt->close();

// nếu không có lịch hẹn
if (empty($appointments)) {
    echo json_encode([
      "status"=>"success",
      "error"=>["code"=>0,"message"=>"Không có lịch hẹn nào"],
      "items"=>[]
    ]);
    $conn->close();
    exit;
}

// Lấy chi tiết service cho tất cả appointment vừa fetch
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

while ($r = $res2->fetch_assoc()) {
    $aid = $r['appointment_id'];
    $appointments[$aid]['services'][] = [
      'service_id'   => $r['service_id'],
      'service_name' => $r['service_name'],
      'service_img'  => $r['service_img'],
      'price'        => $r['price'],
      'time'         => $r['est_time'],
    ];
}

// Lấy serviceInvoice**: tổng `total_price` trong `payment`
$sqlPay = "
  SELECT appointment_id,
         SUM(total_price) AS service_total
    FROM payment
   WHERE appointment_id IN ($placeholders)
   GROUP BY appointment_id
";
$stmt = $conn->prepare($sqlPay);
$stmt->bind_param($types, ...$ids);
$stmt->execute();
$res3 = $stmt->get_result();
while($r = $res3->fetch_assoc()){
  $aid = $r['appointment_id'];
  $appointments[$aid]['serviceInvoice'] = (float)$r['service_total'];
}
$stmt->close();

// Lấy partsInvoice**: tổng (price * quantity) của phụ tùng
$sqlPart = "
  SELECT aps.appointment_id,
         SUM(acc.price * aps.quantity) AS parts_total
    FROM accessory_payment aps
    JOIN accessory acc ON acc.accessory_id = aps.accessory_id
   WHERE aps.appointment_id IN ($placeholders)
   GROUP BY aps.appointment_id
";
$stmt = $conn->prepare($sqlPart);
$stmt->bind_param($types, ...$ids);
$stmt->execute();
$res4 = $stmt->get_result();
while($r = $res4->fetch_assoc()){
  $aid = $r['appointment_id'];
  $appointments[$aid]['partsInvoice'] = (float)$r['parts_total'];
}
$stmt->close();

// Đóng kết nối và trả về JSON
$conn->close();

echo json_encode([
  "status"=>"success",
  "error"=>["code"=>0,"message"=>"Success"],
  "items"=>array_values($appointments)
]);

?>