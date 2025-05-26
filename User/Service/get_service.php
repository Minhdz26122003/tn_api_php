<?php
require_once "../../Config/connectdb.php";
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

if (!isset($input['time'], $input['keyCert'])) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Thiếu tham số"],
        "data"   => null
    ]);
    exit;
}

$time = $input['time'];
$keyCert = $input['keyCert'];

if (!isValidKey($keyCert, $time)) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 403, "message" => "KeyCert không hợp lệ"],
        "data"   => null
    ]);
    exit;
}

// Truy vấn danh sách loại dịch vụ
$sql = "SELECT service_id, service_name ,type_id, price FROM service WHERE is_deleted = 0 ORDER BY service_id DESC";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }

    echo json_encode([
        "status" => "success",
        "error"  => ["code" => 0, "message" => "Success"],
        "items"  => $items
    ]);
} else {
    echo json_encode([
        "status" => "success",
        "error"  => ["code" => 0, "message" => "Không có dữ liệu"],
        "items"  => []
    ]);
}

$conn->close();
?>
