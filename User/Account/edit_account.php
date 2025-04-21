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
    $raw = file_get_contents("php://input");
    $input = json_decode($raw, true);
}

// Nhận dữ liệu
$email    = $input['email'] ?? '';
$fullname = $input['fullname'] ?? '';
$username = $input['username'] ?? '';
$avatar   = $input['avatar'] ?? '';
$gender   = $input['gender'] ?? '';
$birthday = $input['birthday'] ?? null;
$address  = $input['address'] ?? '';
$phonenum = $input['phonenum'] ?? '';
$uid      = $input['uid'] ?? md5(uniqid());

// Kiểm tra email hợp lệ
if (empty($email)) {
    echo json_encode([
        "status" => "error",
        "message" => "Email không được để trống",
    ]);
    exit;
}

// Kiểm tra xem người dùng đã tồn tại chưa
$stmt = $conn->prepare("SELECT uid FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$userExists = $result->num_rows > 0;
$stmt->close();

if ($userExists) {

$username   = isset($input['username']) ? $input['username'] : '';
$avatar   = isset($input['avatar']) ? $input['avatar'] : '';
$fullname = isset($input['fullname']) ? trim($input['fullname']) : '';
$gender   = isset($input['gender']) ? $input['gender'] : '';
$phonenum = isset($input['phonenum']) ? trim($input['phonenum']) : '';
$birthday = isset($input['birthday']) ? $input['birthday'] : null;
$address  = isset($input['address']) ? trim($input['address']) : '';
$email    = isset($input['email']) ? $input['email'] : '';

    // Người dùng đã tồn tại → cập nhật thông tin
    $update = $conn->prepare("UPDATE users SET 
        username = ?, avatar = ?, fullname = ?, gender = ?, 
        birthday = ?, address = ?, phonenum = ?
        WHERE email = ?");
    $update->bind_param("ssssssss", 
        $username, $avatar, $fullname, $gender, 
        $birthday, $address, $phonenum, $email);
    $success = $update->execute();
    

    echo json_encode([
        "status" => "success",
        "error"  => ["code" => 0, "message" => "Sửa thông tin tài khoản thành công"],
        "data"   => $update
    ]);
    $update->close();
} else {
    echo json_encode([
        "status" => "fasle",
        "error"  => ["code" => 500, "message" => "Người dùng không tồn tại"],
        "data"   => $insert
    ]);
     $insert->close();
}

$conn->close();
?>