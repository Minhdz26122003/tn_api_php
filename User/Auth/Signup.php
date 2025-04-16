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
 $data = json_decode(file_get_contents("php://input"), true);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lấy dữ liệu từ request
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';
    $email = $data['email'] ?? '';
    $fullname = $data['fullname'] ?? '';
    $address = $data['address'] ?? '';
    $phonenum = $data['phonenum'] ?? '';
    $birthday = $data['birthday'] ?? '';
    $status = $data['status'] ?? '';
    $gender = $data['gender'] ?? '';
    
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
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $defaultAvatar = "avic.png";
        $status = 0;
        
        $insertStmt = $conn->prepare("INSERT INTO users ( username, email, password, fullname, address, phonenum, birthday, gender, avatar, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insertStmt->bind_param("sssssssisi", $username, $email, $hashedPassword, $fullname, $address, $phonenum, $birthday, $gender, $defaultAvatar, $status);
           
        if ($insertStmt->execute()) {
            $uid = $conn->insert_id; 
            $token = md5(uniqid($uid, true));
            
            echo json_encode([
                "data" => [
                    "token" => $token,
                    "uid" => $uid,
                    "username" => $username,
                    "fullname" => $fullname,
                    "email" => $email,
                    "address" => $address,
                    "phonenum" => $phonenum,
                    "birthday" => $birthday,
                    "gender" => $gender,
                    "avatar" => $defaultAvatar,
                    "status" => $status
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
    // Xử lý preflight request
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