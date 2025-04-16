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
// Kiểm tra kết nối
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
   $username = $decodedData['username'] ?? '';
   $password = $decodedData['password'] ?? '';
   $email = $decodedData['email'] ?? '';
   $fullname = $decodedData['fullname'] ?? '';
   $address = $decodedData['address'] ?? '';
   $phonenum = $decodedData['phonenum'] ?? '';
   $birthday = $decodedData['birthday'] ?? '';
   $status = $decodedData['status'] ?? '';
   $gender = $decodedData['gender'] ?? '';

     // Kiểm tra username hoặc email đã tồn tại chưa
     $checkStmt = $conn->prepare("SELECT username, email FROM users WHERE username = ? OR email = ?");
     $checkStmt->bind_param("ss", $username, $email);
     $checkStmt->execute();
     $checkResult = $checkStmt->get_result();
     
     if ($checkResult->num_rows > 0) {
         $existingUser = $checkResult->fetch_assoc();
         if ($existingUser['username'] === $username) {
             echo json_encode([
                 "status" => "error",
                 "error" => ["code" => 409, "message" => "Tên đăng nhập đã tồn tại"],
                 "data" => null
             ]);
         } else {
             echo json_encode([
                 "status" => "error",
                 "error" => ["code" => 409, "message" => "Email đã được sử dụng"],
                 "data" => null
             ]);
         }
     } else {
         // Mã hóa mật khẩu trước khi lưu vào cơ sở dữ liệu
         $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
         $defaultAvatar = "avic.png";
         $status = 0;
         
         // Thêm người dùng mới vào cơ sở dữ liệu
         $insertStmt = $conn->prepare("INSERT INTO users ( username, email, password, fullname, address, phonenum, birthday, gender, avatar, status) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
         $insertStmt->bind_param("sssssssisi", $username, $email, $hashedPassword, $fullname, $address, $phonenum, $birthday, $gender, $defaultAvatar, $status);
         
         
         if ($insertStmt->execute()) {
             
             $uid = $conn->insert_id;        
             echo json_encode([
                 "data" => [
                    "uid" => $uid,
                     "username" => $username,
                     "fullname" => $fullname,
                     "email" => $email,
                     "address" => $address,
                     "phonenum" => $phonenum,
                     "birthday" => $birthday,
                     "gender" => $gender,
                     "avatar" => $defaultAvatar,
                     "status" => $status,
                     "token" => $token,
                 ],
                 "error" => ["code" => 0, "message" => "Đăng ký thành công"],
             ]);
         } else {
             echo json_encode([
                 "status" => "error",
                 "error" => ["code" => 500, "message" => "Lỗi khi đăng ký: " . $conn->error],
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