<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/responseHelper.php";
require_once "../../Utils/function.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

// define("SECRET_KEY", "minh8386");
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

$email     = $input['email'];
$time    = $input['time'];
$keyCert = $input['keyCert'];


if (!isValidKey($keyCert, $time)) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 403, "message" => "Keysert không hợp lệ"],
        "data"   => null
    ]);
    exit;
}

// Nếu email hợp lệ, thực hiện truy vấn dữ liệu tài khoản
if ($email) {
    $stmt = $conn->prepare("SELECT uid, username, email, fullname, address, phonenum, birthday, gender, avatar, status FROM users WHERE email = ?");
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
        $user = $result->fetch_assoc();
        echo json_encode([
            "status" => "success",
            "error"  => ["code" => 0, "message" => "Success"],
            "data"   => $user
        ]);
    } 
    else {
        echo json_encode([
            "status" => "error",
            "error"  => ["code" => 404, "message" => "Không tìm thấy tài khoản"],
            "data"   => null
        ]);
    }
    $stmt->close();
} else {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Email không hợp lệ"],
        "data"   => null
    ]);
}

$conn->close();
?>
