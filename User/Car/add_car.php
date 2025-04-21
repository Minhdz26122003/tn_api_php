<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/responseHelper.php";
require_once "../../Utils/function.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

$conn = getDBConnection();
$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
}

// Kiểm tra tham số bắt buộc
if (!isset($input['time'], $input['keyCert'], $input['uid'], $input['license_plate'], $input['name'], $input['manufacturer'], $input['year_manufacture'])) {
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
$license_plate = trim($input['license_plate']);
$name = trim($input['name']);
$manufacturer = trim($input['manufacturer']);
$year_manufacture = trim($input['year_manufacture']);

// Kiểm tra key
if (!isValidKey($keyCert, $time)) {
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 403, "message" => "KeyCert không hợp lệ"],
        "data" => null
    ]);
    exit;
}

// Kiểm tra uid tồn tại
$stmt = $conn->prepare("SELECT uid FROM users WHERE uid = ?");
$stmt->bind_param("s", $uid);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 404, "message" => "Người dùng không tồn tại"],
        "data" => null
    ]);
    $stmt->close();
    $conn->close();
    exit;
}
$uid = $result->fetch_assoc()['uid'];
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   

    // Kiểm tra xe đã tồn tại chưa
    $checkStmt = $conn->prepare("SELECT license_plate FROM car WHERE license_plate = ?");
    $checkStmt->bind_param("s", $license_plate);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows > 0) {
        echo json_encode([
            "status" => "error",
            "error" => ["code" => 409, "message" => "Xe đã tồn tại"],
            "data" => null
        ]);
    } else {
        // Thêm xe mới
        $insertStmt = $conn->prepare("INSERT INTO car (license_plate, uid, name, manufacturer, year_manufacture) 
                                      VALUES (?, ?, ?, ?, ?)");
        $insertStmt->bind_param("sisss", $license_plate, $uid, $name, $manufacturer, $year_manufacture);

        if ($insertStmt->execute()) {
            echo json_encode([
                "data" => [
                    "uid" => $uid,
                    "license_plate" => $license_plate,
                    "name" => $name,
                    "manufacturer" => $manufacturer,
                    "year_manufacture" => $year_manufacture
                ],
                "error" => ["code" => 0, "message" => "Thêm xe thành công"]
            ]);
        } else {
            echo json_encode([
                "status" => "error",
                "error" => ["code" => 500, "message" => "Lỗi khi thêm xe: " . $conn->error],
                "data" => null
            ]);
        }
        $insertStmt->close();
    }

    $checkStmt->close();
    $conn->close();
} else if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
} else {
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 405, "message" => "Phương thức không hợp lệ"],
        "data" => null
    ]);
}
?>
