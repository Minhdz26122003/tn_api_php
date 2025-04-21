<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/responseHelper.php";
require_once "../../Utils/function.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

$conn = getDBConnection();

$raw   = file_get_contents("php://input");
$input = json_decode($raw, true);

// Kiểm tra keyCert, time, car_id, license_plate, name, manufacturer, year_manufacture
if (
    !isset($input['keyCert'], $input['time'], $input['car_id'], $input['license_plate'], $input['name'], $input['manufacturer'], $input['year_manufacture'])
) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Thiếu tham số"],
        "data"   => null
    ]);
    exit;
}

$keyCert = $input['keyCert'];
$time    = $input['time'];
$car_id  = (int)$input['car_id'];
$license_plate = trim($input['license_plate']);
$name = trim($input['name']);
$manufacturer = trim($input['manufacturer']);
$year_manufacture = (int)trim($input['year_manufacture']);

// Kiểm tra keyCert & time
if (!isValidKey($keyCert, $time)) {
    http_response_code(403);
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 403, "message" => "KeyCert không hợp lệ hoặc đã hết hạn"],
        "data"   => null
    ]);
    exit;
}

// Thực hiện update
$stmt = $conn->prepare("UPDATE car SET license_plate = ?, name = ?, manufacturer = ?, year_manufacture = ? WHERE car_id = ?");
if ($stmt) {
    $stmt->bind_param("sssii", $license_plate, $name, $manufacturer, $year_manufacture, $car_id);
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode([
                "status" => "success",
                "error"  => ["code" => 0, "message" => "Cập nhật thông tin xe thành công"],
                "data"   => $stmt
            ]);
        } else {
            http_response_code(404);
            echo json_encode([
                "status" => "error",
                "error"  => ["code" => 404, "message" => "Không tìm thấy xe với car_id: $car_id"],
                "data"   => null
            ]);
        }
    } else {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "error"  => ["code" => 500, "message" => "Không thể cập nhật thông tin xe: " . $conn->error],
            "data"   => null
        ]);
    }
    $stmt->close();
} else {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 500, "message" => "Lỗi chuẩn bị câu lệnh SQL"],
        "data"   => null
    ]);
}

$conn->close();
?>