<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token_user.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");
checkToken(); // Gọi hàm kiểm tra token trước khi xử lý
$conn = getDBConnection();

$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
}

// Kiểm tra sự tồn tại của các tham số bắt buộc: uid, time và keyCert
if (!isset($input['uid'], $input['time'], $input['keyCert'])) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Thiếu tham số"],
        "data"   => null
    ]);
    exit;
}

$time = $input['time'];
$keyCert = $input['keyCert'];
$uid     = $input['uid'];

if (!isValidKey($keyCert, $time)) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 403, "message" => "KeyCert không hợp lệ"],
        "data"   => null
    ]);
    exit;
}


if ($uid) {
    $stmt = $conn->prepare("SELECT cr.car_id, cr.license_plate, cr.name, cr.manufacturer, cr.year_manufacture, us.email 
                            FROM car cr 
                            INNER JOIN users us ON cr.uid = us.uid 
                            WHERE us.uid = ? AND cr.is_deleted = 0");
    if (!$stmt) {
        echo json_encode([
            "status" => "error",
            "error"  => ["code" => 500, "message" => "Cơ sở dữ liệu lỗi: " . $conn->error],
            "data"   => null
        ]);
        exit;
    }

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
    $stmt->close();
}


$conn->close();
?>
