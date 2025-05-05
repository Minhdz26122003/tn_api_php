<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require '../../vendor/autoload.php'; 
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");
date_default_timezone_set('UTC');

$conn = getDBConnection();


$input = json_decode(file_get_contents("php://input"), true);
if (!isset($input['data'])) {
    echo json_encode(["success" => false, "message" => "Dữ liệu không hợp lệ"]);
    exit();
}
// Giải mã Base64 với UTF-8
$decodedJson = urldecode(base64_decode($input['data']));
$decodedData = json_decode($decodedJson, true);
if (!$decodedData) {
    echo json_encode(["success" => false, "message" => "Lỗi giải mã dữ liệu"]);
    exit();
}


if (!isset($decodedData['username']) || !isset($decodedData['password'])) {
    echo json_encode([
        'success' => false,
        'message' => "Thiếu tên đăng nhập hoặc mật khẩu.",
    ]);
    exit();
}

$username = $decodedData['username'];
$password = $decodedData['password'];


// Truy vấn thông tin người dùng dựa trên username
$query = "SELECT * FROM users WHERE username = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "s", $username);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$issuedAt = time(); 
$expires_at = $issuedAt + 3600; 

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();

    if ($user && password_verify($password, $user['password'])) {
        // Tạo token JWT
        $payload = [
            "iss" => "yourdomain.com",
            "iat" => $issuedAt,
            "exp" => $expires_at, 
            "uid" => $user['uid'],
            "username" => $user['username'],
            "status" => $user['status']
        ];

        $token = JWT::encode($payload, $secretKey, 'HS256');

        echo json_encode([
            "data" => [
                "uid" => $user['uid'],
                "username" => $user['username'],
                "fullname" => $user['fullname'],
                "email" => $user['email'],
                "address" => $user['address'],
                "phonenum" => $user['phonenum'],
                "birthday" => $user['birthday'],
                "gender" => $user['gender'],
                "avatar" => $user['avatar'],
                "status" => $user['status'],
                "token" => $token, // Trả về token
                "permissions" => getPermissionsByRole($user['status'])
            ],
            "error" => ["code" => 0, "message" => "Success"],
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "error" => ["code" => 401, "message" => "Sai mật khẩu"],
            "data" => null
        ]);
    }
} else {
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 404, "message" => "Tài khoản không tồn tại"],
        "data" => null
    ]);
}

mysqli_close($conn);
?>