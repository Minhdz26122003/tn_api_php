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

// Kiểm tra keyCert, time, car_id
if (
    !isset($input['keyCert'], $input['time'], $input['car_id'])
    || !is_numeric($input['car_id'])
) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Thiếu hoặc sai định dạng tham số"],
        "data"   => null
    ]);
    exit;
}

$keyCert = $input['keyCert'];
$time    = $input['time'];
$carId   = (int)$input['car_id'];

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

$stmt = $conn->prepare("UPDATE car SET status = 1 WHERE car_id = ?");
$stmt->bind_param("i", $carId);
if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode([
            "status" => "success",
            "error"  => ["code" => 0, "message" => "Success"],
            "data"   => null
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            "status" => "error",
            "error"  => ["code" => 404, "message" => "Failed: $carId"],
            "data"   => null
        ]);
    }
} else {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 500, "message" => "Failed: " . $conn->error],
        "data"   => null
    ]);
}

$stmt->close();
$conn->close();
?>