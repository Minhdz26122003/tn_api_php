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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
    if ($page < 1) $page = 1;
    if ($limit < 1) $limit = 20;
    $offset = ($page - 1) * $limit;

    // Đếm tổng số bản ghi
    $countSql = "SELECT COUNT(*) as total FROM appointment";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalAppointment = 0;
    if ($row = $countResult->fetch_assoc()) {
        $totalAppointment = intval($row['total']);
    }
    $countStmt->close();

    $statusCounts = [
        'all'           => $totalAppointment,   // ← thêm dòng này
        'unconfirmed' => 0, // Chưa xác nhận
        'quote_appoint' => 0, // Đang báo giá 
        'accepted_quote' => 0, // Chấp nhận báo giá
        'under_repair' => 0,   // Đang sửa
        'completed' => 0, // Hoàn thành
        'settlement' => 0, // Quyết toán
        'pay'=> 0, // Thânh toán
        'paid' => 0, // Đã thanh toán
        'canceled' => 0,    // Đã hủy
    ];

 
    $statusSql = "
        SELECT 
            status, 
            COUNT(*) as count 
        FROM 
            appointment 
        GROUP BY 
            status
    ";
    $statusStmt = $conn->prepare($statusSql);
    $statusStmt->execute();
    $statusResult = $statusStmt->get_result();

    while ($row = $statusResult->fetch_assoc()) {
        $status = intval($row['status']);
        $count = intval($row['count']);
        if ($status === 0) $statusCounts['unconfirmed'] = $count;
        elseif ($status === 1) $statusCounts['quote_appoint'] = $count;
        elseif ($status === 2) $statusCounts['accepted_quote'] = $count;
        elseif ($status === 3) $statusCounts['under_repair'] = $count;
        elseif ($status === 4) $statusCounts['completed'] = $count;
        elseif ($status === 5) $statusCounts['settlement'] = $count;
        elseif ($status === 6) $statusCounts['pay'] = $count;
        elseif ($status === 7) $statusCounts['paid'] = $count;
        elseif ($status === 8) $statusCounts['canceled'] = $count;
       
    }
    $statusStmt->close();

    // Câu SQL chính với phân trang
    $sql = "
        SELECT 
            lh.appointment_id,
            lh.uid,
            lh.car_id, 
            lh.gara_id,      
            lh.description, 
            lh.appointment_date, 
            lh.appointment_time,
            lh.status,
            lh.reason,
            tk.username,
            cr.license_plate,
            tt.gara_name, 
            COALESCE(GROUP_CONCAT(DISTINCT dv.service_name SEPARATOR ', '), '') AS service_name
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
        GROUP BY 
            lh.appointment_id,
            lh.uid,
            tk.username,
            lh.description, 
            lh.car_id,
            cr.license_plate,
            lh.gara_id,
            lh.reason,
            tt.gara_name,        
            lh.appointment_date, 
            lh.appointment_time,
            lh.status
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    $appointmentList = [];
    while ($row = $result->fetch_assoc()) {
        $appointmentList[] = $row;
    }
    $stmt->close();

    $totalPages = ceil($totalAppointment / $limit);

    // Thêm statusCounts vào phản hồi
    $response = array(
        "data" => $appointmentList,
        "currentPage" => $page,
        "totalPages" => $totalPages,
        "totalAppointment" => $totalAppointment,
        "statusCounts" => $statusCounts, 
    );
    echo json_encode($response);
} else {
    http_response_code(405);
    echo json_encode(["message" => "Phương thức không được hỗ trợ. Chỉ hỗ trợ GET."]);
}
$conn->close();
?>