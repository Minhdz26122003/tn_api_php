<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token.php"; 
require_once "../../Utils/verify_token_user.php";

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json"); 

date_default_timezone_set('Asia/Ho_Chi_Minh');

checkToken();

$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
}

// Kiểm tra các tham số đầu vào cần thiết
if (!isset($input['keyCert'], $input['time'], $input['appointment_id'], $input['total_price'], $input['uid'])) {
    echo json_encode([
      "status"=>"error",
      "error"=>["code"=>400,"message"=>"Thiếu tham số bắt buộc (keyCert, time, appointment_id, total_price, uid)."],
      "data"=>null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$appointment_id = (int) $input['appointment_id'];
$total_price_input = (float) $input['total_price'];
$uid = (int) $input['uid']; 
$keyCert = $input['keyCert'];
$time = $input['time'];

if (!isValidKey($keyCert, $time)) {
    echo json_encode([
      "status"=>"error",
      "error"=>["code"=>403,"message"=>"KeyCert không hợp lệ"],
      "data"=>null
    ]);
    exit;
}

$appointment_id = $input['appointment_id'] ?? null;
$total_price_from_flutter = isset($input['total_price']) ? (float)$input['total_price'] : 0;
$uid_from_flutter = $input['uid'] ?? null; 

// Validate input
if (!$appointment_id || $total_price_from_flutter <= 0 || !$uid_from_flutter) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Dữ liệu không hợp lệ (thiếu appointment_id, total_price hoặc uid).',
        'error' => ['code' => 400]
    ]);
    exit;
}

// === BẮT ĐẦU TRANSACTION ===
$conn->begin_transaction();
$payment_database_id = 0; // Đây sẽ là payment_id của bản ghi đã tồn tại

try {
    // 1. Tìm bản ghi thanh toán đã tồn tại với appointment_id và status = 0 (chưa thanh toán)

    $stmtFindPayment = $conn->prepare("SELECT payment_id, status, form, total_price FROM payment WHERE appointment_id = ? AND status = 0 ORDER BY payment_id DESC LIMIT 1");
    if (!$stmtFindPayment) {
        throw new Exception("Lỗi chuẩn bị câu lệnh (tìm payment): " . $conn->error);
    }
    $stmtFindPayment->bind_param("i", $appointment_id);
    $stmtFindPayment->execute();
    $resultFindPayment = $stmtFindPayment->get_result();
    $existingPayment = $resultFindPayment->fetch_assoc();
    $stmtFindPayment->close();

    if ($resultFindPayment->num_rows == 0) {
    // Nếu không tìm thấy bản ghi nào với status = 0
    error_log("Lỗi CSDL khi khởi tạo thanh toán VNPAY (add_online.php): Không tìm thấy bản ghi thanh toán chưa xử lý cho lịch hẹn ID: " . $appointment_id);
    echo json_encode([
        "status" => "error",
        "error"  => ["code" => 404, "message" => "Không tìm thấy bản ghi thanh toán chưa xử lý cho lịch hẹn này."],
        "data"   => null
    ]);
    exit; 
}

    $payment_database_id = $existingPayment['payment_id'];
    $existing_total_price = $existingPayment['total_price'];

    
    if ($existing_total_price != $total_price_from_flutter) {
        throw new Exception("Số tiền gửi lên không khớp với số tiền trong bản ghi thanh toán đã tồn tại.");
    }

    // 2. Cập nhật bản ghi thanh toán đã tồn tại
    // Cập nhật status thành 0 (đang chờ VNPAY - thì là chưa thanh toán 0), form thành 1 (online), và payment_date
    $payment_status_pending_vnpay = 0; // Trạng thái: đang chờ thanh toán VNPAY
    $payment_form_online = 1; // Form: online
    $current_payment_date = date('Y-m-d H:i:s'); 

    $sqlUpdatePayment = "UPDATE payment SET payment_date = ?, form = ?, status = ? WHERE payment_id = ?";
    $stmtUpdatePayment = $conn->prepare($sqlUpdatePayment);
    if (!$stmtUpdatePayment) {
        throw new Exception("Lỗi chuẩn bị câu lệnh (cập nhật payment): " . $conn->error);
    }
  
    $stmtUpdatePayment->bind_param("siii", $current_payment_date, $payment_form_online, $payment_status_pending_vnpay, $payment_database_id);
    $stmtUpdatePayment->execute();

    if ($stmtUpdatePayment->affected_rows === 0) {
        throw new Exception("Không thể cập nhật bản ghi thanh toán ID: $payment_database_id. Có thể đã được cập nhật bởi tiến trình khác.");
    }
    $stmtUpdatePayment->close();

    // 3. Cập nhật trạng thái lịch hẹn sang "Đang chờ thanh toán VNPAY" thi là vẫn chưa thanh toán 6)
    $appointment_status_pending_vnpay = 6;
    $stmtUpdateLichHen = $conn->prepare("UPDATE appointment SET status = ? WHERE appointment_id = ?");
    if (!$stmtUpdateLichHen) {
        throw new Exception("Lỗi chuẩn bị câu lệnh (cập nhật lichhen): " . $conn->error);
    }
    $stmtUpdateLichHen->bind_param("ii", $appointment_status_pending_vnpay, $appointment_id);
    $stmtUpdateLichHen->execute();
    $stmtUpdateLichHen->close();

    $conn->commit(); // Commit transaction nếu mọi thứ thành công
} catch (Exception $e) {
    $conn->rollback(); // Rollback nếu có lỗi xảy ra trong quá trình ghi DB
    error_log("Lỗi CSDL khi khởi tạo thanh toán VNPAY (add_online.php): " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Lỗi khi khởi tạo thanh toán: ' . $e->getMessage(),
        'error' => ['code' => 500]
    ]);
    exit;
}

// === Định nghĩa các biến VNPAY động SAU KHI đã có payment_database_id, appointment_id và uid ===
// Các biến này sẽ được truyền vào VNPAY
$vnp_TxnRef = (string)$payment_database_id; // Mã tham chiếu giao dịch là payment_id từ DB của bạn
$vnp_OrderInfo = "Thanh toan lich hen #" . $appointment_id . " - Payment ID: " . $payment_database_id . " - User ID: " . $uid_from_flutter;
$vnp_Amount = $total_price_from_flutter * 100; // VNPAY yêu cầu số tiền tính bằng xu (VND * 100)
$vnp_Locale = 'vn'; // Ngôn ngữ thanh toán (vn/en)
$vnp_CurrCode = 'VND'; // Đơn vị tiền tệ
$vnp_Command = 'pay';
$vnp_OrderType = 'billpayment';
$vnp_CreateDate = date('YmdHis');
$vnp_ExpireDate = date('YmdHis', strtotime('+15 minutes', time())); // Hạn thanh toán 15 phút

// Lấy IP của người dùng. Ưu tiên REMOTE_ADDR nếu có, nếu không fallback về HTTP_X_FORWARDED_FOR hoặc localhost
$vnp_IpAddr = $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '127.0.0.1');

// === Require configVnpay.php SAU KHI các biến động đã được định nghĩa ===

require_once "../../Utils/configVnpay.php"; 

$inputData = array(
    "vnp_Version" => "2.1.0",
    "vnp_TmnCode" => $vnp_TmnCode,
    "vnp_Amount" => $vnp_Amount,
    "vnp_Command" => $vnp_Command,
    "vnp_CreateDate" => $vnp_CreateDate,
    "vnp_CurrCode" => $vnp_CurrCode,
    "vnp_IpAddr" => $vnp_IpAddr,
    "vnp_Locale" => $vnp_Locale,
    "vnp_OrderInfo" => $vnp_OrderInfo,
    "vnp_OrderType" => $vnp_OrderType,
    "vnp_ReturnUrl" => $vnp_Returnurl,
    "vnp_TxnRef" => $vnp_TxnRef,
    "vnp_ExpireDate" => $vnp_ExpireDate, 
);

if (isset($vnp_BankCode) && $vnp_BankCode != "") {
    $inputData['vnp_BankCode'] = $vnp_BankCode;
}

ksort($inputData); // Sắp xếp các tham số theo thứ tự alphabet
$query = "";
$hashdata = "";
$i = 0;
foreach ($inputData as $key => $value) {
    if ($i == 1) {
        $hashdata .= '&' . urlencode((string)$key) . "=" . urlencode((string)$value);
    } else {
        $hashdata .= urlencode((string)$key) . "=" . urlencode((string)$value);
        $i = 1;
    }
    $query .= urlencode((string)$key) . "=" . urlencode((string)$value) . '&';
}

$vnp_SecureHash = hash_hmac('sha512', $hashdata, $vnp_HashSecret); // Tạo chữ ký
$query .= 'vnp_SecureHash=' . $vnp_SecureHash;
$vnpay_redirect_url = $vnp_Url . "?" . $query; // URL chuyển hướng đến VNPAY

echo json_encode([
    'status' => 'success',
    "error"=>["code"=>0,"message"=>"Tạo yêu cầu thanh toán VNPAY thành công."],
    'payment_url' => $vnpay_redirect_url,
    'payment_id_created' => $payment_database_id // Trả về payment_id đã tạo trong DB
]);

exit;
?>