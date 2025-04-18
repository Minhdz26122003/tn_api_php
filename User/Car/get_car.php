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

// Kiểm tra sự tồn tại của các tham số bắt buộc: uid, time và keyCert
if (!isset($input['email'], $input['time'], $input['keyCert'])) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Thiếu tham số"],
        "data"   => null
    ]);
    exit;
}

$time = $input['time'];
$keyCert = $input['keyCert'];
$email     = $input['email'];

if (!isValidKey($keyCert, $time)) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 403, "message" => "KeyCert không hợp lệ"],
        "data"   => null
    ]);
    exit;
}

// Truy vấn danh sách loại dịch vụ
if ($email) {
    $stmt = $conn->prepare("SELECT cr.license_plate, us.email 
                            FROM car cr 
                            INNER JOIN users us ON cr.uid = us.uid 
                            WHERE us.email = ?");
    if (!$stmt) {
        echo json_encode([
            "status" => "error",
            "error"  => ["code" => 500, "message" => "Cơ sở dữ liệu lỗi: " . $conn->error],
            "data"   => null
        ]);
        exit;
    }

    $stmt->bind_param("s", $email);
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
