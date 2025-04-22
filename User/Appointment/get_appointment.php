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
$sql = "SELECT * FROM appointment WHERE uid = ? ORDER BY appointment_id DESC";
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
