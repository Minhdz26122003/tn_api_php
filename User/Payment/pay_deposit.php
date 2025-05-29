<?php
// File: apihm/User/Payment/pay_deposit.php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token.php";
require_once "../../Utils/verify_token_user.php";
require_once "../../Utils/configVnpay.php"; // Chứa $vnp_TmnCode, $vnp_HashSecret, $vnp_Url, $vnp_Returnurl

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

date_default_timezone_set('Asia/Ho_Chi_Minh');

$log_file_deposit = __DIR__ . '/vnpay_deposit_debug_log.txt';
function write_deposit_log($message) {
    global $log_file_deposit;
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents($log_file_deposit, "[{$timestamp}] " . $message . "\n", FILE_APPEND);
}

write_deposit_log("------ PAY_DEPOSIT REQUEST RECEIVED ------");

$conn = getDBConnection();

// Lấy dữ liệu từ raw POST body
$input = file_get_contents('php://input');
$data = json_decode($input, true);

write_deposit_log("Raw POST Data: " . json_encode($data));

if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(["status" => "error", "error" => ["code" => 400, "message" => "Invalid JSON input."], "data" => null], JSON_UNESCAPED_UNICODE);
    exit;
}

// Kiểm tra và lấy các tham số cần thiết
$deposit_id = $data['deposit_id'] ?? null;
$amount = $data['amount'] ?? null;
$uid = $data['uid'] ?? null; // ID của người dùng khởi tạo thanh toán
$appointment_id = $data['appointment_id'] ?? null; // ID của lịch hẹn

if ($deposit_id === null || $amount === null || $uid === null || $appointment_id === null) {
    echo json_encode(["status" => "error", "error" => ["code" => 400, "message" => "Thiếu thông tin bắt buộc: deposit_id, amount, uid, appointment_id."], "data" => null], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn->begin_transaction();

    // 1. Kiểm tra deposit_id có tồn tại và status = 0 (chưa thanh toán)
    // Đã sửa: Bỏ 'AND uid = ?' khỏi mệnh đề WHERE
    $stmt_check = $conn->prepare("SELECT status FROM deposits WHERE deposit_id = ? AND amount = ? AND appointment_id = ? AND status = 0 FOR UPDATE");
    if (!$stmt_check) {
        throw new Exception("Lỗi chuẩn bị câu lệnh SELECT deposits: " . $conn->error);
    }
    // ĐÃ SỬA LẠI: Đảm bảo thứ tự và kiểu dữ liệu chính xác: deposit_id (integer), amount (double), appointment_id (integer)
    $stmt_check->bind_param("idi", $deposit_id, $amount, $appointment_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();

    if ($result_check->num_rows === 0) {
        $stmt_check->close();
        $conn->rollback();
        write_deposit_log("Error: Deposit record for deposit_id " . $deposit_id . " (v4) not found, amount/appointment_id mismatch, or already processed (status != 0).");
        echo json_encode(["status" => "error", "error" => ["code" => 404, "message" => "Không tìm thấy bản ghi đặt cọc hoặc đã được xử lý/thông tin không khớp."], "data" => null], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stmt_check->close();

    // 2. Cập nhật trạng thái deposit thành 0 (Pending) và lưu thông tin VNPAY nếu cần
    // Mặc dù status đã là 0, nhưng đây là nơi bạn có thể đánh dấu rằng

    $sql_update = "UPDATE deposits SET status = 0, deposit_date = NOW() WHERE deposit_id = ? AND status = 0";
    $stmt_update = $conn->prepare($sql_update);
    if (!$stmt_update) {
        throw new Exception("Lỗi chuẩn bị câu lệnh UPDATE deposit: " . $conn->error);
    }
    $stmt_update->bind_param("i", $deposit_id);
    if (!$stmt_update->execute()) {
        throw new Exception("Lỗi thực thi cập nhật deposit: " . $stmt_update->error);
    }
    
    if ($stmt_update->affected_rows === 0) {
        $stmt_update->close();
        $conn->rollback();
        write_deposit_log("Error: Deposit record for deposit_id " . $deposit_id . " (v4) could not be updated to status 0 (possibly already processed or status changed).");
        echo json_encode(["status" => "error", "error" => ["code" => 409, "message" => "Bản ghi đặt cọc có thể đã được xử lý hoặc trạng thái đã thay đổi. Vui lòng thử lại hoặc kiểm tra."], "data" => null], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stmt_update->close();
    $conn->commit();

    // 3. Chuẩn bị dữ liệu cho VNPAY
    $vnp_Amount = $amount * 100; // Số tiền phải nhân 100
    // THAY ĐỔI TẠI ĐÂY: Thêm tiền tố 'DP' vào vnp_TxnRef_val
    $vnp_TxnRef_val = 'DP' . $deposit_id; // <-- ĐÂY LÀ CHỖ CẦN SỬA

    $vnp_OrderInfo_val = "Thanh toan tien coc lich hen #" . $appointment_id . " cho KH #" . $uid;
    $vnp_OrderType = "deposit"; // Loại đơn hàng: đặt cọc

    $vnp_IpAddr = $_SERVER['REMOTE_ADDR'];
    $vnp_CreateDate = date('YmdHis');
    $vnp_ExpireDate = date('YmdHis', strtotime('+15 minutes', strtotime($vnp_CreateDate))); // Hết hạn sau 15 phút

    $inputData = array(
        "vnp_Version" => "2.1.0",
        "vnp_TmnCode" => $vnp_TmnCode,
        "vnp_Command" => "pay",
        "vnp_CurrCode" => "VND",
        "vnp_TxnRef" => $vnp_TxnRef_val, // Sử dụng giá trị đã được sửa đổi
        "vnp_Amount" => $vnp_Amount,
        "vnp_OrderInfo" => $vnp_OrderInfo_val,
        "vnp_OrderType" => $vnp_OrderType,
        "vnp_Locale" => "vn",
        "vnp_ReturnUrl" => $vnp_Returnurl,
        "vnp_IpAddr" => $vnp_IpAddr,
        "vnp_CreateDate" => $vnp_CreateDate,
        "vnp_ExpireDate" => $vnp_ExpireDate
    );

    ksort($inputData);
    $hashData = "";
    foreach ($inputData as $key => $value) {
        $hashData .= $key . '=' . urlencode($value) . '&';
    }
    $hashData = rtrim($hashData, '&');

    write_deposit_log("VNPAY Request (v4) - InputData (sorted for VNPAY): " . json_encode($inputData));
    write_deposit_log("VNPAY Request (v4) - Hash Data String for HMAC (before HMAC): " . $hashData);
    write_deposit_log("VNPAY Request (v4) - Hash Secret Used (first 5 chars): " . substr($vnp_HashSecret, 0, 5) . "...");

    $secureHash = hash_hmac('sha512', $hashData, $vnp_HashSecret);
    write_deposit_log("VNPAY Request (v4) - Generated Secure Hash: " . $secureHash);

    $vnpay_payment_url_final = $vnp_Url . "?" . $hashData . '&vnp_SecureHash=' . $secureHash;
    write_deposit_log("VNPAY Request (v4) - Full Payment URL: " . $vnpay_payment_url_final);

    echo json_encode([
        "status" => "success",
        "error"=>["code"=>0,"message"=>"Tạo yêu cầu thanh toán cọc VNPAY thành công."],
        "data" => [
            "payment_url" => $vnpay_payment_url_final,
            "deposit_id" => $deposit_id,
            "appointment_id" => $appointment_id,
            "vnp_txn_ref" => $vnp_TxnRef_val // Trả về giá trị đã có tiền tố DP
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($conn) { // Không có inTransaction() trong MySQLi, chỉ cần rollback nếu có kết nối
        @$conn->rollback(); // Rollback nếu có lỗi xảy ra
    }
    error_log("Lỗi trong pay_deposit.php: " . $e->getMessage());
    write_deposit_log("System error (Exception): " . $e->getMessage());
    echo json_encode(["status" => "error", "error" => ["code" => 500, "message" => "Lỗi hệ thống: " . $e->getMessage()], "data" => null], JSON_UNESCAPED_UNICODE);
} finally {
    if ($conn) {
        $conn->close();
    }
}
?>