<?php
require_once "../../Utils/responseHelper.php";
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

$conn = getDBConnection();
$data = json_decode(file_get_contents("php://input"), true);

// Kiểm tra keycert và time
if (!isset($data['keycert']) || !isset($data['time'])) {
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 400, "message" => "Thiếu keycert hoặc time"],
        "data" => null
    ]);
    exit;
}

$keyCert = $data['keycert'];
$time = $data['time'];

if (!isValidKey($keyCert, $time)) {
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 401, "message" => "Keycert không hợp lệ hoặc hết hạn"],
        "data" => null
    ]);
    exit;
}

// Kiểm tra xem email có trong dữ liệu đầu vào không
if (!isset($data['email'])) {
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 400, "message" => "Email là bắt buộc"],
        "data" => null
    ]);
    exit;
}

$email = $data['email'];

// Kiểm tra xem người dùng đã tồn tại chưa
$stmt = $conn->prepare("SELECT uid FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$userExists = $result->num_rows > 0;
$stmt->close();

if ($userExists) {
    $stmt = $conn->prepare("SELECT uid, username, email, fullname, address, phonenum, birthday, gender, avatar, status FROM users WHERE email = ?");   
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
  
} else {
    // Người dùng chưa tồn tại → thêm mới
    $username = isset($data['username']) ? $data['username'] : '';
    $avatar = isset($data['avatar']) ? $data['avatar'] : '';
    $fullname = isset($data['fullname']) ? trim($data['fullname']) : '';
    $gender = isset($data['gender']) ? $data['gender'] : '';
    $phonenum = isset($data['phonenum']) ? trim($data['phonenum']) : '';
    $birthday = isset($data['birthday']) ? $data['birthday'] : null;
    $address = isset($data['address']) ? trim($data['address']) : '';

    $insert = $conn->prepare("INSERT INTO users (email, username, avatar, fullname, gender, birthday, address, phonenum) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $insert->bind_param("ssssssss", 
        $email, $username, $avatar, $fullname, $gender, $birthday, $address, $phonenum);
    $success = $insert->execute();

    if ($success) {
        echo json_encode([
            "status" => "success",
            "error" => ["code" => 0, "message" => "Thêm mới tài khoản thành công"],
            "data" => ["email" => $email]
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "error" => ["code" => 500, "message" => "Thêm mới tài khoản thất bại"],
            "data" => null
        ]);
    }
    $insert->close();
}

$conn->close();
?>