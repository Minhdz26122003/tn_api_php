<?php
require '../../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
date_default_timezone_set('UTC'); 
$secretKey = "minh8386"; 
define("SECRET_KEY", "minh8386");

function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function generateToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

function generateOTP($length = 6) {
    return str_pad(random_int(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
}


//  Hàm kiểm tra
function isValidKey($keyCert, $time) {
    if (!$time || !$keyCert) return false;
    // Parse theo UTC/local tương ứng
    $requestTime = strtotime($time);
    if ($requestTime === false || abs(time() - $requestTime) > 300) {
        return false;
    }
    $expectedKey = md5(SECRET_KEY . $time);
    return hash_equals($expectedKey, $keyCert);
}


function getPermissionsByRole($status) {
    $permissions = [];

    if ($status == 1) { // Admin
        $permissions = [
            'dashboard', 'account', 'service', 'center',
            'sercen', 'sale', 'appointment', 'payment', 'review', 'profile'
        ];
    } else if ($status == 2) { // Nhân viên
        $permissions = ['dashboard', 'service', 'center', 'appointment', 'profile'];
    }

    return $permissions;
}

// Hàm kiểm tra token
// function isValidToken($token) {
//     global $secretKey;

//     if (!$token) {
//         error_log("Token rỗng");
//         return ["valid" => false, "message" => "Token rỗng"];
//     }
//     try {
//         $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));
//         error_log("Token hợp lệ: " . print_r($decoded, true));
//         return ["valid" => true, "message" => "Token hợp lệ"];
//     } catch (Exception $e) {
//         error_log("Lỗi giải mã token: " . $e->getMessage());
//         return ["valid" => false, "message" => $e->getMessage()];
//     }
// }

// ?>
