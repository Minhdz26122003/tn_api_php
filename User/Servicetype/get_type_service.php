<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token_user.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

checkToken(); 

$conn = getDBConnection();
// Đọc input
$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
}

//Kiểm tra keyCert + time
if (!isset($input['time'], $input['keyCert'])) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 400, "message" => "Thiếu tham số"],
        "data"   => null
    ]);
    exit;
}

$time    = $input['time'];
$keyCert = $input['keyCert'];

if (!isValidKey($keyCert, $time)) {
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 403, "message" => "KeyCert không hợp lệ hoặc hết hạn"],
        "data"   => null
    ]);
    exit;
}


$sql = "
  SELECT 
    st.type_id, st.type_name,
    s.service_id, s.service_name, s.service_img, s.price, s.description
  FROM service_type st
  LEFT JOIN service s ON s.type_id = st.type_id
  ORDER BY st.type_id DESC, s.service_name ASC
";
$result = $conn->query($sql);

$types = []; 
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $tid = $row['type_id'];
        if (!isset($types[$tid])) {
            $types[$tid] = [
                'type_id'   => $tid,
                'type_name' => $row['type_name'],
                'services'  => []
            ];
        }
        
        if (!empty($row['service_id'])) {
            $types[$tid]['services'][] = [
                'service_id'   => $row['service_id'],
                'service_name' => $row['service_name'],
                'service_img'  => $row['service_img'],
                'price'  => $row['price'],
                'description'  => $row['description'],
            ];
        }
    }
}

// Trả về json
echo json_encode([
    "status" => "success",
    "error"  => ["code" => 0, "message" => "Thành công"],
    
    "items"  => array_values($types),
]);

$conn->close();
?>