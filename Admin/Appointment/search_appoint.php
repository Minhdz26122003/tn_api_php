<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

$conn = getDBConnection();
// Kiểm tra kết nối
if ($conn->connect_error) {
    die("Kết nối thất bại: " . $conn->connect_error);
}
// Lấy token từ header
$headers = getallheaders();
$token = $headers["Authorization"] ?? "";

// Xác thực token
if (!verifyToken($token)) {
    echo json_encode([
        "success" => false,
        "message" => "Token không hợp lệ hoặc đã hết hạn"
    ]);
    $conn->close();
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);
if (isset($_GET['start_date']) && isset($_GET['end_date'])) {
    $start_date = $_GET['start_date'];
    $end_date = $_GET['end_date'];  
    if (strtotime($start_date) && strtotime($end_date)) {
        
        $query = "
        SELECT 
         lh.appointment_id,
            lh.uid,
            tk.username,
            lh.car_id,
            cr.license_plate,
            lh.gara_id,
            tt.gara_name,
            dv.service_name,
            lh.appointment_date, 
            lh.appointment_time,
            lh.status,
            
        
        COALESCE(GROUP_CONCAT(dv.service_name SEPARATOR ', '), '') AS service_name
    FROM 
        appointment lh
    LEFT JOIN 
        detail_appointment lhdv ON lh.appointment_id = lhdv.appointment_id
    LEFT JOIN 
        service dv ON lhdv.service_id = dv.service_id
    LEFT JOIN 
        gara tt ON lh.gara_id = tt.gara_id
    LEFT JOIN 
        car cr ON lh.car_id = cr.car_id
    LEFT JOIN 
        users tk ON lh.uid = tk.uid       
    WHERE lh.appointment_date BETWEEN ? AND ?

    GROUP BY 
        lh.appointment_id
        ";
        if ($stmt = $conn->prepare($query)) {
      
            $stmt->bind_param('ss', $start_date, $end_date);
            $stmt->execute();
            $result = $stmt->get_result();

            $appointments = [];
            while ($row = $result->fetch_assoc()) {
                $appointments[] = $row;
            }
        
            if (empty($appointments)) {
                echo json_encode(['success' => false, 'message' => 'Không có lịch hẹn nào trong khoảng thời gian này']);
            } else {
               
                echo json_encode(['success' => true, 'appointments' => $appointments]);
            }
            $stmt->close(); 
        } else {
            echo json_encode(['success' => false, 'message' => 'Lỗi khi chuẩn bị câu truy vấn']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Ngày không hợp lệ']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Thiếu tham số ngày']);
}
?>