<?php
require '../../vendor/autoload.php';
require_once "../../Utils/function.php";

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function checkToken() {
    $secretKey = "minh8386"; 
    $headers = getallheaders();
    if (!isset($headers['Authorization'])) {
        return false; // Không có token
    }

    $authHeader = $headers['Authorization'];
    if (strpos($authHeader, 'Bearer ') !== 0) {
        return false; 
    }

    $token = substr($authHeader, 7);
    try {
        $decoded = JWT::decode($token, new Key($secretKey, 'HS256')); 
        return $decoded; // Trả về thông tin từ token nếu hợp lệ
    } catch (Exception $e) {
        return false; // Token không hợp lệ
    }
}

// Hàm giả lập kiểm tra token
function isValidToken($token) {
    // Ví dụ: return true nếu token hợp lệ, false nếu không
    return !empty($token); // Thay bằng logic xác thực của bạn
}
?>