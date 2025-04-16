
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
if (!isset($input['uid'], $input['time'], $input['keyCert'])) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Thiếu tham số uid, time hoặc keyCert"],
        "data"   => null
    ]);
    exit;
}

$uid     = $input['uid'];
$time    = $input['time'];
$keyCert = $input['keyCert'];

// Xác thực keyCert
if (!isValidKey($keyCert, $time)) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 403, "message" => "Keysert không hợp lệ"],
        "data"   => null
    ]);
    exit;
}

// Lấy và validate dữ liệu
$username   = isset($input['username']) ? $input['username'] : '';
$avatar   = isset($input['avatar']) ? $input['avatar'] : '';
$fullname = isset($input['fullname']) ? trim($input['fullname']) : '';
$gender   = isset($input['gender']) ? $input['gender'] : '';
$phonenum = isset($input['phonenum']) ? trim($input['phonenum']) : '';
$birthday = isset($input['birthday']) ? $input['birthday'] : null;
$address  = isset($input['address']) ? trim($input['address']) : '';
$email    = isset($input['email']) ? $input['email'] : '';

// Validation
if (empty($fullname)) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Tên không được để trống"],
        "data"   => null
    ]);
    exit;
}

if ($uid) {
    $stmt = $conn->prepare("UPDATE users SET 
        username = ?,
        avatar = ?, 
        fullname = ?, 
        gender = ?, 
        phonenum = ?, 
        birthday = ?, 
        address = ?, 
        email = ? 
        WHERE uid = ?");
    
    if (!$stmt) {
        echo json_encode([
            "status" => "error",
            "error"  => ["code" => 500, "message" => "Cơ sở dữ liệu lỗi: " . $conn->error],
            "data"   => null
        ]);
        exit;
    }
    
    $stmt->bind_param("sssssssss", 
        $username,
        $avatar,
        $fullname,
        $gender,
        $phonenum,
        $birthday,
        $address,
        $email,
        $uid
    );
    
    if ($stmt->execute()) {
        $selectStmt = $conn->prepare("SELECT uid, username, email, fullname, address, phonenum, birthday, gender, avatar FROM users WHERE uid = ?");
        $selectStmt->bind_param("s", $uid);
        $selectStmt->execute();
        $result = $selectStmt->get_result();
        $user = $result->fetch_assoc();
        
        echo json_encode([
            "status" => "success",
            "error"  => ["code" => 0, "message" => "Sửa thông tin tài khoản thành công"],
            "data"   => $user
        ]);
        
        $selectStmt->close();
    } else {
        echo json_encode([
            "status" => "error",
            "error"  => ["code" => 500, "message" => "Sửa thông tin tài khoản không thành công: " . $stmt->error],
            "data"   => null
        ]);
    }
    
    $stmt->close();
} else {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Uid không hợp lệ"],
        "data"   => null
    ]);
}

$conn->close();
?>