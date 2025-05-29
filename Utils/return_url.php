 <?php

require_once __DIR__ .'/../Utils/configVnpay.php';
require_once __DIR__ .'/../Config/connectdb.php'; 
require_once __DIR__ .'/../Utils/ipn_listener.php';

// === Cấu hình hiển thị lỗi (chỉ trong môi trường phát triển) ===
// error_reporting(E_ALL);
// ini_set('display_errors', 1);
header("Content-Type: text/html; charset=utf-8"); // Trả về HTML cho trình duyệt/WebView
$conn = getDBConnection();

// Đường dẫn file log cho return_url
$log_file = __DIR__ . '/vnpay_return_debug_log.txt';

// Hàm ghi log để debug
function write_log($message) {
    global $log_file;
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents($log_file, "[{$timestamp}] " . $message . "\n", FILE_APPEND);
}

write_log("------ RETURN_URL REQUEST RECEIVED ------");
write_log("GET Data: " . json_encode($_GET));

// Dữ liệu sẽ trả về cho Flutter qua Deep Link
$dataForReactNative = [
    'outcome' => 'failed',
    'message' => 'Có lỗi xảy ra trong quá trình xử lý thanh toán.',
    'transaction_type' => 'unknown', // <-- THÊM TRƯỜNG MỚI ĐỂ PHÂN BIỆT LOẠI GIAO DỊCH
    'id' => '', // <-- ID CỦA PAYMENT HOẶC DEPOSIT
    'appointment_id' => null, // <-- THÊM appointment_id
    'uid' => null, // <-- THÊM uid (nếu có trong OrderInfo)
    'amount' => 0,
    'vnp_ResponseCode' => '99',
    'vnp_TransactionStatus' => '99',
    'vnp_OrderInfo' => '',
    'vnp_TxnRef' => '', // Thêm vnp_TxnRef vào dữ liệu trả về để tiện debug/kiểm tra
];

$queryParams = $_GET; // Lấy tất cả tham số từ URL
$vnp_SecureHash_received = $queryParams['vnp_SecureHash'] ?? ''; // Lấy chữ ký nhận được

// Loại bỏ các tham số không cần thiết để tính hash
if (isset($queryParams['vnp_SecureHashType'])) unset($queryParams['vnp_SecureHashType']);
if (isset($queryParams['vnp_SecureHash'])) unset($queryParams['vnp_SecureHash']);
ksort($queryParams); // Sắp xếp lại theo thứ tự alphabet

$hashDataString = "";
$i = 0;
foreach ($queryParams as $key => $value) {
    $hashDataString .= ($i == 0 ? '' : '&') . urlencode((string)$key) . "=" . urlencode((string)$value);
    $i = 1;
}

$secureHash = hash_hmac('sha512', $hashDataString, $vnp_HashSecret);

// Lấy các tham số VNPAY từ URL
$vnp_ResponseCode = $_GET['vnp_ResponseCode'] ?? '99';
$vnp_TxnRef = $_GET['vnp_TxnRef'] ?? '';
$vnp_Amount = ($_GET['vnp_Amount'] ?? 0) / 100; // Chia lại cho 100 để có số tiền thực tế
$vnp_OrderInfo = $_GET['vnp_OrderInfo'] ?? '';
$vnp_TransactionStatus = $_GET['vnp_TransactionStatus'] ?? '99';
$vnp_BankCode = $_GET['vnp_BankCode'] ?? '';
$vnp_TransactionNo = $_GET['vnp_TransactionNo'] ?? '';
$vnp_PayDate = $_GET['vnp_PayDate'] ?? '';

// Cập nhật các thông tin cơ bản vào $dataForReactNative
$dataForReactNative['vnp_ResponseCode'] = $vnp_ResponseCode;
$dataForReactNative['vnp_TransactionStatus'] = $vnp_TransactionStatus;
$dataForReactNative['vnp_OrderInfo'] = $vnp_OrderInfo;
$dataForReactNative['amount'] = $vnp_Amount;
$dataForReactNative['vnp_TxnRef'] = $vnp_TxnRef; // Lưu vnp_TxnRef vào đây

// Trích xuất appointment_id và uid từ vnp_OrderInfo nếu có
preg_match('/lich hen #(\d+)/', $vnp_OrderInfo, $matches_app);
if (isset($matches_app[1])) {
    $dataForReactNative['appointment_id'] = (int)$matches_app[1];
}
preg_match('/User ID: (\d+)/', $vnp_OrderInfo, $matches_uid);
if (isset($matches_uid[1])) {
    $dataForReactNative['uid'] = (int)$matches_uid[1];
}


if ($secureHash == $vnp_SecureHash_received) {
    write_log("RETURN_URL Checksum MATCHED. vnp_TxnRef: $vnp_TxnRef, ResponseCode: $vnp_ResponseCode, Amount: $vnp_Amount");

    $conn->begin_transaction();

    // PHÂN BIỆT LOẠI GIAO DỊCH DỰA VÀO vnp_TxnRef (tiền tố 'DP' cho đặt cọc)
    if (strpos($vnp_TxnRef, 'DP') === 0) {
        // Đây là giao dịch đặt cọc
        $deposit_id_ref = (int) substr($vnp_TxnRef, 2); // Bỏ tiền tố 'DP'
        $dataForReactNative['id'] = $deposit_id_ref; // Gán deposit_id vào trường 'id'
        $dataForReactNative['transaction_type'] = 'deposit'; // Đặt loại giao dịch
        write_log("Detected DEPOSIT transaction. Deposit ID: $deposit_id_ref");

        // Lấy thông tin đặt cọc từ DB
        $sql_select_deposit = "SELECT status, amount, appointment_id FROM deposits WHERE deposit_id = ? FOR UPDATE";
        $stmt_select_deposit = $conn->prepare($sql_select_deposit);
        if (!$stmt_select_deposit) {
            throw new Exception("Lỗi chuẩn bị câu lệnh SELECT deposit: " . $conn->error);
        }
        $stmt_select_deposit->bind_param("i", $deposit_id_ref);
        $stmt_select_deposit->execute();
        $result_deposit = $stmt_select_deposit->get_result();

        if ($result_deposit->num_rows > 0) {
            $deposit = $result_deposit->fetch_assoc();
            $currentDepositStatus = $deposit['status'];
            $deposit_amount_in_db = $deposit['amount'];
            $appointment_id_from_db = $deposit['appointment_id']; // Lấy appointment_id từ bản ghi deposit
            $stmt_select_deposit->close();

            // Cập nhật appointment_id vào dataForReactNative (quan trọng cho Flutter)
            $dataForReactNative['appointment_id'] = $appointment_id_from_db;

            // Kiểm tra số tiền để tránh giả mạo
            if ($deposit_amount_in_db != $vnp_Amount) {
                write_log("RETURN_URL: Deposit amount mismatch. DB: $deposit_amount_in_db, VNPAY: $vnp_Amount");
                $dataForReactNative['message'] = 'Lỗi: Số tiền đặt cọc không khớp.';
                $conn->rollback();
            }
            // Chỉ cập nhật nếu trạng thái hiện tại là 0 (pending)
            else if ($currentDepositStatus == 0) {
                $update_deposit_status = 0; // Mặc định là thất bại
                $update_appointment_status = null;

                if ($vnp_ResponseCode == '00' && $vnp_TransactionStatus == '00') {
                    // Giao dịch đặt cọc thành công
                    $update_deposit_status = 1; // Đã thanh toán
                    $update_appointment_status = 2; // Cập nhật trạng thái appointment sang 2 (đã đặt cọc)

                    $dataForReactNative['outcome'] = 'success';
                    $dataForReactNative['message'] = 'Đặt cọc thành công!';
                    write_log("RETURN_URL: Deposit ID $deposit_id_ref SUCCESS. Updating deposit status to 1 and appointment status to 2.");
                } else {
                    // Giao dịch đặt cọc thất bại (bao gồm cả bị hủy giữa chừng)
                   
                    $update_deposit_status =0; // Đặt về 0 (pending) hoặc 2 (thất bại) tùy vào yêu cầu của bạn.
                                             
                    $dataForReactNative['outcome'] = 'failed';
                    $dataForReactNative['message'] = 'Đặt cọc thất bại hoặc bị hủy: ' . ($vnp_OrderInfo ?: 'Lỗi không xác định.');
                    write_log("RETURN_URL: Deposit ID $deposit_id_ref FAILED/CANCELED. ResponseCode: $vnp_ResponseCode, TransactionStatus: $vnp_TransactionStatus. Updating deposit status to $update_deposit_status.");
                }

                // Cập nhật trạng thái bản ghi đặt cọc trong DB
                $sql_update_deposit = "UPDATE deposits SET status = ?, deposit_date= NOW(), vnpay_transaction_id = ?, vnpay_response_code = ?, vnpay_transaction_status = ? WHERE deposit_id = ?";
                $stmt_update_deposit = $conn->prepare($sql_update_deposit);
                if (!$stmt_update_deposit) {
                    throw new Exception("Lỗi chuẩn bị câu lệnh UPDATE deposit (return): " . $conn->error);
                }
                $stmt_update_deposit->bind_param("isssi", $update_deposit_status, $vnp_TransactionNo, $vnp_ResponseCode, $vnp_TransactionStatus, $deposit_id_ref);
                if (!$stmt_update_deposit->execute()) {
                    throw new Exception("Lỗi thực thi câu lệnh UPDATE deposit (return): " . $stmt_update_deposit->error);
                }
                $stmt_update_deposit->close();

                // Nếu đặt cọc thành công, cập nhật trạng thái appointment
                if ($update_appointment_status !== null) {
                    $sql_update_appointment = "UPDATE appointment SET status = ? WHERE appointment_id = ?";
                    $stmt_update_appointment = $conn->prepare($sql_update_appointment);
                    if (!$stmt_update_appointment) {
                        throw new Exception("Lỗi chuẩn bị câu lệnh UPDATE appointment (deposit return): " . $conn->error);
                    }
                    $stmt_update_appointment->bind_param("ii", $update_appointment_status, $appointment_id_from_db);
                    if (!$stmt_update_appointment->execute()) {
                        throw new Exception("Lỗi thực thi câu lệnh UPDATE appointment (deposit return): " . $stmt_update_appointment->error);
                    }
                    $stmt_update_appointment->close();
                    write_log("RETURN_URL: Appointment ID $appointment_id_from_db status updated to $update_appointment_status (deposit success).");
                }
                $conn->commit(); // Hoàn thành giao dịch nếu mọi thứ thành công
            } else {
                // Đơn hàng đặt cọc đã được xử lý bởi IPN hoặc ở trạng thái khác
                write_log("RETURN_URL: Deposit record for DP$deposit_id_ref is in an unhandleable state ($currentDepositStatus). Already processed or not pending.");
                $dataForReactNative['outcome'] = ($currentDepositStatus == 1) ? 'success' : 'failed';
                $dataForReactNative['message'] = 'Giao dịch đặt cọc đã được xử lý. Vui lòng kiểm tra lại trạng thái đơn hàng.';
                $conn->rollback(); // Không thay đổi trạng thái nếu đã xử lý
            }
        } else {
            write_log("RETURN_URL: Deposit record NOT FOUND for vnp_TxnRef: " . $vnp_TxnRef . ".");
            $dataForReactNative['message'] = 'Không tìm thấy thông tin đặt cọc.';
            $conn->rollback();
        }

    } else {
        // Đây là giao dịch thanh toán đầy đủ (payment) - GIỮ NGUYÊN LOGIC CŨ CỦA BẠN
        $payment_id_ref = (int) $vnp_TxnRef;
        $dataForReactNative['id'] = $payment_id_ref; // Gán payment_id vào trường 'id'
        $dataForReactNative['transaction_type'] = 'full_payment'; // Đặt loại giao dịch
        write_log("Detected FULL PAYMENT transaction. Payment ID: $payment_id_ref");

        // Lấy thông tin thanh toán từ DB (giữ nguyên code cũ của bạn)
        $sql_select_payment = "SELECT status, total_price, appointment_id FROM payment WHERE payment_id = ? FOR UPDATE";
        $stmt_select_payment = $conn->prepare($sql_select_payment);
        if (!$stmt_select_payment) {
            throw new Exception("Lỗi chuẩn bị câu lệnh SELECT payment: " . $conn->error);
        }
        $stmt_select_payment->bind_param("i", $payment_id_ref);
        $stmt_select_payment->execute();
        $result_payment = $stmt_select_payment->get_result();

        if ($result_payment->num_rows > 0) {
            $payment = $result_payment->fetch_assoc();
            $currentPaymentStatus = $payment['status'];
            $payment_amount_in_db = $payment['total_price'];
            $appointment_id_from_db = $payment['appointment_id'];
            $stmt_select_payment->close();

            // Cập nhật appointment_id vào dataForReactNative
            $dataForReactNative['appointment_id'] = $appointment_id_from_db;

            // Kiểm tra số tiền để tránh giả mạo
            if ($payment_amount_in_db != $vnp_Amount) {
                write_log("RETURN_URL: Payment amount mismatch. DB: $payment_amount_in_db, VNPAY: $vnp_Amount");
                $dataForReactNative['message'] = 'Lỗi: Số tiền thanh toán không khớp.';
                $conn->rollback();
            }
            // Chỉ cập nhật nếu trạng thái hiện tại là 0 (pending)
            else if ($currentPaymentStatus == 0) {
                $update_payment_status = -1; // Mặc định là thất bại
                $update_appointment_status = null;

                if ($vnp_ResponseCode == '00' && $vnp_TransactionStatus == '00') {
                    // Giao dịch thanh toán đầy đủ thành công
                    $update_payment_status = 1; // Đã thanh toán
                    // Cập nhật trạng thái appointment (giả sử thanh toán đầy đủ chuyển appointment.status = 5)
                    $update_appointment_status = 7 ; // Ví dụ: 5 là 'đã thanh toán đầy đủ'

                    $dataForReactNative['outcome'] = 'success';
                    $dataForReactNative['message'] = 'Thanh toán thành công!';
                    write_log("RETURN_URL: Payment ID $payment_id_ref SUCCESS. Updating payment status to 1 and appointment status to 5.");
                } else {
                    // Giao dịch thanh toán đầy đủ thất bại
                    $update_payment_status = 2; // Thất bại
                    $dataForReactNative['outcome'] = 'failed';
                    $dataForReactNative['message'] = 'Thanh toán thất bại: ' . ($vnp_OrderInfo ?: 'Lỗi không xác định.');
                    write_log("RETURN_URL: Payment ID $payment_id_ref FAILED. ResponseCode: $vnp_ResponseCode, TransactionStatus: $vnp_TransactionStatus. Updating payment status to 2.");
                }

                // Cập nhật trạng thái bản ghi thanh toán trong DB
                $sql_update_payment = "UPDATE payment SET status = ?, form = 1, payment_date = NOW() WHERE payment_id = ?";
                $stmt_update_payment = $conn->prepare($sql_update_payment);
                if (!$stmt_update_payment) {
                    throw new Exception("Lỗi chuẩn bị câu lệnh UPDATE payment (return): " . $conn->error);
                }
                $stmt_update_payment->bind_param("ii", $update_payment_status, $payment_id_ref);
                if (!$stmt_update_payment->execute()) {
                    throw new Exception("Lỗi thực thi câu lệnh UPDATE payment (return): " . $stmt_update_payment->error);
                }
                $stmt_update_payment->close();

                // Nếu thanh toán đầy đủ thành công, cập nhật trạng thái appointment
                if ($update_appointment_status !== null) {
                    $sql_update_appointment = "UPDATE appointment SET status = ? WHERE appointment_id = ?";
                    $stmt_update_appointment = $conn->prepare($sql_update_appointment);
                    if (!$stmt_update_appointment) {
                        throw new Exception("Lỗi chuẩn bị câu lệnh UPDATE appointment (payment return): " . $conn->error);
                    }
                    $stmt_update_appointment->bind_param("ii", $update_appointment_status, $appointment_id_from_db);
                    if (!$stmt_update_appointment->execute()) {
                        throw new Exception("Lỗi thực thi câu lệnh UPDATE appointment (payment return): " . $stmt_update_appointment->error);
                    }
                    $stmt_update_appointment->close();
                    write_log("RETURN_URL: Appointment ID $appointment_id_from_db status updated to $update_appointment_status (full payment success).");
                }
                $conn->commit(); // Hoàn thành giao dịch nếu mọi thứ thành công
            } else {
                // Đơn hàng thanh toán đã được xử lý bởi IPN hoặc ở trạng thái khác
                write_log("RETURN_URL: Payment record for $payment_id_ref is in an unhandleable state ($currentPaymentStatus). Already processed or not pending.");
                $dataForReactNative['outcome'] = ($currentPaymentStatus == 1) ? 'success' : 'failed';
                $dataForReactNative['message'] = 'Giao dịch thanh toán đã được xử lý. Vui lòng kiểm tra lại trạng thái đơn hàng.';
                $conn->rollback(); // Không thay đổi trạng thái nếu đã xử lý
            }
        } else {
            write_log("RETURN_URL: Payment record NOT FOUND for vnp_TxnRef: " . $vnp_TxnRef . ".");
            $dataForReactNative['message'] = 'Không tìm thấy thông tin thanh toán.';
            $conn->rollback();
        }
    }

} else {
    // Chữ ký không hợp lệ
    write_log("RETURN_URL Signature INVALID. Received: $vnp_SecureHash_received, Expected: $secureHash.");
    $dataForReactNative['message'] = 'Chữ ký không hợp lệ.';
    $dataForReactNative['vnp_ResponseCode'] = '97'; // VNPAY invalid signature code
}

// Bắt lỗi hệ thống
if ($conn && $conn->connect_error) {
    write_log("RETURN_URL Database connection error: " . $conn->connect_error);
    $dataForReactNative['message'] = 'Lỗi hệ thống: Không thể kết nối cơ sở dữ liệu.';
    $dataForReactNative['outcome'] = 'failed';
} elseif (isset($e)) { // Kiểm tra nếu có ngoại lệ được ném ra
    write_log("RETURN_URL System error (Exception): " . $e->getMessage());
    $dataForReactNative['message'] = 'Lỗi hệ thống: ' . $e->getMessage();
    $dataForReactNative['outcome'] = 'failed';
}

if ($conn) {
    $conn->close();
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kết quả thanh toán VNPAY</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 20px; background-color: #f4f4f4; }
        h1 { color: #333; }
        .success { color: #28a745; font-weight: bold; }
        .failed { color: #dc3545; font-weight: bold; } 
        .pending { color: #ffc107; font-weight: bold; } 
        .processed { color: #17a2b8; font-weight: bold; } 
        .error { color: #6c757d; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Đang xử lý kết quả thanh toán...</h1>
    <p class="<?php echo $dataForReactNative['outcome']; ?>">
        <?php echo htmlspecialchars($dataForReactNative['message']); ?>
    </p>

    <script type="text/javascript">
        window.onload = function() {
            const data = <?php echo json_encode($dataForReactNative); ?>;
            const queryString = new URLSearchParams(data).toString();

            // THAY 'apphm' BẰNG SCHEME CỦA ỨNG DỤNG CỦA BẠN
            const appSchemeUrl = `apphm://payment?${queryString}`;

            console.log("Attempting to open deep link:", appSchemeUrl);

            // Cố gắng mở URL scheme
            window.location.href = appSchemeUrl;

            // Fallback nếu deep link không hoạt động
            setTimeout(function() {
                // Nếu đây là WebView của Flutter, gửi postMessage để giao tiếp với Flutter
                if (window.ReactNativeWebView) {
                    window.ReactNativeWebView.postMessage(JSON.stringify(data));
                    console.log("Sent message to ReactNativeWebView:", data);
                } else {
                    // Fallback cho trình duyệt thông thường
                    alert("Kết quả thanh toán: " + data.message + "\nVui lòng quay lại ứng dụng.");
                }
            }, 1500); // Cho 1.5 giây để trình duyệt xử lý scheme URL
        };
    </script>
</body>
</html>