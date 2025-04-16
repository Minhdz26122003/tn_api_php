<?php
function getDBConnection() {
    $servername = "localhost"; // Hoặc địa chỉ server database
    $username   = "root"; // Tài khoản MySQL của bạn
    $password   = ""; // Mật khẩu MySQL của bạn
    $dbname = "apphmdb"; 

    // Kết nối MySQL bằng MySQLi (hướng đối tượng)
    $conn = new mysqli($servername, $username, $password, $dbname);

    // Kiểm tra kết nối
    if ($conn->connect_error) {
        die(json_encode(["error" => ["code" => 500, "message" => "Lỗi kết nối Database: " . $conn->connect_error]]));
    }

    // Thiết lập charset để tránh lỗi tiếng Việt
    $conn->set_charset("utf8mb4");

    return $conn;
}
