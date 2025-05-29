<?php
// get_payment_detail.php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token_user.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

// Kiểm tra token user
checkToken();

// Lấy input JSON
$conn = getDBConnection();
$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
}

// Validate param
if (!isset($input['time'], $input['keyCert'], $input['uid'], $input['appointment_id'])) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Thiếu tham số"],
        "data"   => null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$time           = $input['time'];
$keyCert        = $input['keyCert'];
$uid            = trim($input['uid']);
$appointment_id = intval($input['appointment_id']);

// Check KeyCert
if (!isValidKey($keyCert, $time)) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 403, "message" => "KeyCert không hợp lệ hoặc hết hạn"],
        "data"   => null
    ]);
    exit;
}

// Lấy thông tin cuộc hẹn để kiểm tra uid
$sql = "SELECT uid FROM appointment WHERE appointment_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $appointment_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 404, "message" => "Không tìm thấy cuộc hẹn"],
        "data"   => null
    ]);
    exit;
}
$appointment_info = $result->fetch_assoc();
if ($appointment_info['uid'] != $uid) {
     echo json_encode([
        "status" => "error",
        "error"  => ["code" => 403, "message" => "Bạn không có quyền truy cập cuộc hẹn này"],
        "data"   => null
    ]);
    exit;
}
$stmt->close();


// Lấy chi tiết dịch vụ
$sql1 = "SELECT da.service_id, s.service_name, s.price
         FROM detail_appointment da
         JOIN service s ON s.service_id = da.service_id
         WHERE da.appointment_id = ?";
$stmt1 = $conn->prepare($sql1);
$stmt1->bind_param("i", $appointment_id);
$stmt1->execute();
$services = $stmt1->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt1->close();

// Lấy chi tiết phụ tùng
$sql2 = "SELECT ap.accessory_id, a.accessory_name, ap.quantity,
                a.price,
                (ap.quantity * a.price) AS sub_total
         FROM accessory_payment ap
         JOIN accessory a ON a.accessory_id = ap.accessory_id
         WHERE ap.appointment_id = ?";
$stmt2 = $conn->prepare($sql2);
$stmt2->bind_param("i", $appointment_id);
$stmt2->execute();
$parts = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt2->close();



// Lấy số tiền đặt cọc từ bảng deposits
$deposit_amount = 0.0;
$stmtDeposit = $conn->prepare("SELECT amount FROM deposits WHERE appointment_id = ? AND status = 1");
$stmtDeposit->bind_param("i", $appointment_id);
$stmtDeposit->execute();
$resultDeposit = $stmtDeposit->get_result();

if ($resultDeposit->num_rows > 0) {
    $rowDeposit = $resultDeposit->fetch_assoc();
    $deposit_amount = floatval($rowDeposit['amount']);
}
$stmtDeposit->close();

// Tính tổng
$service_total = array_sum(array_map(fn($r)=>(float)$r['price'],      $services));
$parts_total   = array_sum(array_map(fn($r)=>(float)$r['sub_total'],  $parts));
$total_all     = ($service_total + $parts_total);
$total_after     = ($service_total + $parts_total)-$deposit_amount;
// Đảm bảo tổng tiền cuối cùng không âm
if ($total_all < 0) {
    $total_all = 0.0;
}


echo json_encode([
    "status" => "success",
    "error"  => ["code" => 0, "message" => "Thành công"],
    "data"   => [
        "services"       => $services,
        "parts"          => $parts,
        "service_total"  => $service_total,
        "parts_total"    => $parts_total,
        "total_after"          => $total_after,
        "total"          => $total_all,
        "deposit_amount" => $deposit_amount // Thêm tiền đặt cọc vào phản hồi
    ]
]);

$conn->close();
?>