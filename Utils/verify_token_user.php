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
        return $decoded; 
    } catch (Exception $e) {
        return false; 
    }
}

// Hàm giả lập kiểm tra token
function isValidToken($token) {
    
    return !empty($token);
}
?>