<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

$conn = getDBConnection();
$data = json_decode(file_get_contents("php://input"), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? ''; 

    $stmt = $conn->prepare("SELECT uid, username, email, password, fullname, address, phonenum, birthday, gender ,avatar, status FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();

        // Sử dụng password_verify() để kiểm tra mật khẩu
        if (password_verify($password, $user['password'])) {
            $token = md5(uniqid($user['uid'], true));

            echo json_encode([
                "data" => [
                    "token" => $token,
                    "uid" => $user['uid'],
                    "username" => $user['username'],
                    "fullname" => $user['fullname'],
                    "email" => $user['email'],
                    "address" => $user['address'],
                    "phonenum" => $user['phonenum'],
                    "birthday" => $user['birthday'],
                    "gender" => $user['gender'],
                    "avatar" => $user['avatar'],
                    "status" => $user['status']
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

    $stmt->close();
    $conn->close();

} else {
    echo json_encode([
        "status" => "error",
        "error" => ["code" => 405, "message" => "Phương thức không hợp lệ"],
        "data" => null
    ]);
}
?>
