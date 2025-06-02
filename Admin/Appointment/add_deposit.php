<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php"; // Chứa các hàm hỗ trợ, ví dụ: isValidKey (nếu có)
require_once "../../Utils/verify_token.php"; // Để xác thực token của Admin

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

$conn = getDBConnection();

// Kiểm tra kết nối
if ($conn->connect_error) {
    echo json_encode(["success" => false, "message" => "Kết nối CSDL thất bại: " . $conn->connect_error]);
    exit();
}

// Lấy token từ header
$headers = getallheaders();
$token = $headers["Authorization"] ?? "";


if (!verifyToken($token)) {
    echo json_encode(["success" => false, "message" => "Token không hợp lệ hoặc đã hết hạn"]);
    $conn->close();
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Kiểm tra tham số appointment_id
    if (!isset($data['appointment_id'])) {
        echo json_encode(["success" => false, "message" => "Thiếu tham số appointment_id."]);
        $conn->close();
        exit();
    }

    $appointment_id = intval($data['appointment_id']);
    
   
    $conn->begin_transaction();

    try {
        // 1. Kiểm tra xem lịch hẹn có tồn tại và đang ở trạng thái chờ xác nhận không (ví dụ: status = 0)
       
        $stmt_check_appointment = $conn->prepare("SELECT status FROM appointment WHERE appointment_id = ?");
        $stmt_check_appointment->bind_param("i", $appointment_id);
        $stmt_check_appointment->execute();
        $result_check = $stmt_check_appointment->get_result();
        
        if ($result_check->num_rows === 0) {
            throw new Exception("Lịch hẹn không tồn tại.");
        }
        
        $appointment_info = $result_check->fetch_assoc();
        // Giả định trạng thái ban đầu là 0, trạng thái báo giá là 0
        // Nếu đã có deposit hoặc trạng thái đã là báo giá/đã xác nhận, thì không tạo lại.
        if ($appointment_info['status'] != 0) { 
            throw new Exception("Lịch hẹn không ở trạng thái chờ xác nhận. Không thể tạo đặt cọc.");
        }
        $stmt_check_appointment->close();

        // 2. Lấy tổng tiền dịch vụ của lịch hẹn
        $total_service_price = 0;
        $stmt_services = $conn->prepare("
            SELECT SUM(s.price) AS total_price
            FROM detail_appointment da
            JOIN service s ON s.service_id = da.service_id
            WHERE da.appointment_id = ?
        ");
        $stmt_services->bind_param("i", $appointment_id);
        $stmt_services->execute();
        $result_services = $stmt_services->get_result();
        if ($row = $result_services->fetch_assoc()) {
            $total_service_price = (float)$row['total_price'];
        }
        $stmt_services->close();

        if ($total_service_price <= 0) {
            throw new Exception("Không tìm thấy dịch vụ hoặc tổng tiền dịch vụ không hợp lệ cho lịch hẹn này.");
        }

        // 3. Tính toán số tiền đặt cọc (ví dụ: 30% tổng tiền dịch vụ)
        $deposit_percentage = 0.30; // 30%
        $deposit_amount = round($total_service_price * $deposit_percentage); // Làm tròn số tiền đặt cọc

        // 4. Kiểm tra xem đã có bản ghi đặt cọc pending cho appointment này chưa
        $stmt_existing_deposit = $conn->prepare("SELECT deposit_id FROM deposits WHERE appointment_id = ? AND status = 0 LIMIT 1");
        $stmt_existing_deposit->bind_param("i", $appointment_id);
        $stmt_existing_deposit->execute();
        $result_existing_deposit = $stmt_existing_deposit->get_result();
        
        if ($result_existing_deposit->num_rows > 0) {
            // Đã có bản ghi đặt cọc pending, không tạo mới
            $conn->rollback(); // Hủy bỏ giao dịch vì không cần thay đổi gì
            echo json_encode(["success" => true, "message" => "Đã có bản ghi đặt cọc pending cho lịch hẹn này. Không tạo lại."]);
            $stmt_existing_deposit->close();
            $conn->close();
            exit();
        }
        $stmt_existing_deposit->close();

        // 5. Ghi bản ghi đặt cọc vào bảng 'deposits'
        // Status: 0 = Pending, 1 = Paid, 2 = Failed
        $deposit_status = 0; // Trạng thái pending
        $stmt_insert_deposit = $conn->prepare("INSERT INTO deposits (appointment_id, deposit_date, amount, status, created_at) VALUES (?,NOW() ,?, ?, NOW())");
        $stmt_insert_deposit->bind_param("idi", $appointment_id, $deposit_amount, $deposit_status);

        if (!$stmt_insert_deposit->execute()) {
            throw new Exception("Lỗi khi tạo bản ghi đặt cọc: " . $stmt_insert_deposit->error);
        }
        $deposit_id = $conn->insert_id; // Lấy ID của bản ghi đặt cọc vừa tạo
        $stmt_insert_deposit->close();

        // 6. Cập nhật trạng thái lịch hẹn sang "đang báo giá" (ví dụ: status =1)
        // Cần định nghĩa rõ các mã trạng thái trong hệ thống của bạn.
        // Ví dụ: 0: Chờ xác nhận, 1: Đã xác nhận/Đang chờ báo giá, 2: Đã đặt cọc
        $new_appointment_status = 1;
        $stmt_update_appointment = $conn->prepare("UPDATE appointment SET status = ? WHERE appointment_id = ?");
        $stmt_update_appointment->bind_param("ii", $new_appointment_status, $appointment_id);

        if (!$stmt_update_appointment->execute()) {
            throw new Exception("Lỗi khi cập nhật trạng thái lịch hẹn: " . $stmt_update_appointment->error);
        }
        $stmt_update_appointment->close();

        // Hoàn tất giao dịch
        $conn->commit();

        echo json_encode([
            "success" => true,
            "message" => "Đã tạo đặt cọc và cập nhật trạng thái lịch hẹn thành công.",
            "deposit_id" => $deposit_id,
            "deposit_amount" => $deposit_amount
        ]);

    } catch (Exception $e) {
        // Rollback giao dịch nếu có lỗi
        $conn->rollback();
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    } finally {
        $conn->close();
    }
} else {
    echo json_encode(["success" => false, "message" => "Phương thức không được hỗ trợ."]);
    $conn->close();
}
?>