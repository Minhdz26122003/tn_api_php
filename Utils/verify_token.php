<?php
require '../../vendor/autoload.php';
require_once "../../Utils/function.php";

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secretKey = "minh8386";

function verifyToken($token) {
    global $secretKey;

    if (!$token || !str_starts_with($token, "Bearer ")) {
        return false;
    }
    $jwt = substr($token, 7);

    try {
        // Chỉ kiểm tra signature, không ném ExpiredException
        JWT::decode($jwt, new Key($secretKey, 'HS256'));
        return true;
    } catch (Exception $e) {
        // Bỏ qua ExpiredException, chỉ return false khi lỗi signature
        return false;
    }
}
