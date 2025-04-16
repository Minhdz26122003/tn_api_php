<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

$conn = getDBConnection();
if ($conn->connect_error) {
    die("Kết nối thất bại: " . $conn->connect_error);
}

// Lấy token từ header
$headers = getallheaders();
$token = $headers["Authorization"] ?? "";

// Xác thực token
if (!verifyToken($token)) {
    echo json_encode([
        "success" => false,
        "message" => "Token không hợp lệ hoặc đã hết hạn"
    ]);
    $conn->close();
    exit();
}


$input = json_decode(file_get_contents("php://input"), true);
if (!isset($input['data'])) {
    echo json_encode(["success" => false, "message" => "Dữ liệu không hợp lệ"]);
    exit();
}
// Giải mã Base64 với UTF-8 an toàn
$decodedJson =urldecode(base64_decode($input['data']));
$decodedData = json_decode($decodedJson, true);
if (!$decodedData) {
    echo json_encode(["success" => false, "message" => "Lỗi giải mã dữ liệu"]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Lấy dữ liệu từ request
    $uid  = $decodedData['uid'] ?? ''; 
    $username = $decodedData['username'] ?? '';
    $email = $decodedData['email'] ?? '';
    $fullname = $decodedData['fullname'] ?? '';
    $address = $decodedData['address'] ?? '';
    $phonenum = $decodedData['phonenum'] ?? '';
    $birthday = $decodedData['birthday'] ?? '';
    $gender = $decodedData['gender'] ?? '';
    $status = $decodedData['status'] ?? '';

// Kiểm tra định dạng email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        "success" => false,
        "message" => "Email không hợp lệ"
    ]);
    $conn->close();
    exit();
}

// Kiểm tra email đã tồn tại (trừ email của chính user này)
$checkEmailStmt = $conn->prepare("SELECT uid FROM users WHERE email = ? AND uid != ?");
$checkEmailStmt->bind_param('si', $email, $uid);
$checkEmailStmt->execute();
$checkEmailResult = $checkEmailStmt->get_result();
if ($checkEmailResult->num_rows > 0) {
    echo json_encode([
        "success" => false,
        "message" => "Email đã được sử dụng bởi người dùng khác"
    ]); 
    $checkEmailStmt->close();
    $conn->close();
    exit();
}
$checkEmailStmt->close();

// Cập nhật thông tin tài khoản
$query = "UPDATE users SET username = ?, email = ?, fullname = ?, address = ?, phonenum = ?, birthday = ?, gender = ?,status= ? WHERE uid = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('ssssssiii', $username, $email, $fullname, $address, $phonenum, $birthday, $gender, $status, $uid);

if ($stmt->execute()) {
    echo json_encode([
        "success" => true,
        "message" => "Sửa thông tin tài khoản thành công"
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Sửa thông tin tài khoản không thành công: " . $conn->error
    ]);
}
$stmt->close();
$conn->close();
}
?>
