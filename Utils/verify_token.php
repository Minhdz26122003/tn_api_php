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

    $jwt = str_replace("Bearer ", "", $token);
    try {
        $decoded = JWT::decode($jwt, new Key($secretKey, 'HS256'));
        return true;
    } catch (Exception $e) {
        return false;
    }
}
?>