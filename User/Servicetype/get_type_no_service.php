<?php
// get_type_service.php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token_user.php"; // Có thể bỏ nếu API này không yêu cầu token

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

// checkToken(); // Bỏ comment nếu API này yêu cầu xác thực token

$conn = getDBConnection();
$conn->set_charset("utf8mb4");

// Đọc input
$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
}

//Kiểm tra keyCert + time
if (!isset($input['time'], $input['keyCert'])) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Missing parameters"],
        "data"   => null
    ]);
    exit;
}

$time    = $input['time'];
$keyCert = $input['keyCert'];

if (!isValidKey($keyCert, $time)) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 403, "message" => "Invalid KeyCert or expired"],
        "data"   => null
    ]);
    exit;
}

// Truy vấn chỉ lấy loại dịch vụ
$sql = "SELECT type_id, type_name FROM service_type WHERE is_deleted = 0 ORDER BY type_id ASC";
$result = $conn->query($sql);

$types = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $types[] = [
            'type_id'   => $row['type_id'],
            'type_name' => $row['type_name'],
        ];
    }
}

// Trả về json
echo json_encode([
    "status" => "success",
    "error"  => ["code" => 0, "message" => "Success"],
    "items"  => $types, // Trả về danh sách type trực tiếp
]);

$conn->close();
?>