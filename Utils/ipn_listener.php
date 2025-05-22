<?php
require_once __DIR__ .'/../Config/connectdb.php'; 
require_once __DIR__ .'/../Utils/configVnpay.php';

// === Cấu hình hiển thị lỗi (chỉ trong môi trường phát triển) ===
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

header("Content-Type: application/json"); 

date_default_timezone_set('Asia/Ho_Chi_Minh');
$conn = getDBConnection();
// Đường dẫn file log 
$log_file = __DIR__ . '/vnpay_ipn_debug_log.txt'; 

// Hàm ghi log để debug
function write_log($message) {
    global $log_file;
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents($log_file, "[{$timestamp}] " . $message . "\n", FILE_APPEND);
}

write_log("------ IPN REQUEST RECEIVED ------");
write_log("GET Data: " . json_encode($_GET));

$returnData = array(); // Mảng sẽ chứa kết quả trả về cho VNPAY

try {
    $vnp_SecureHash = $_GET['vnp_SecureHash'] ?? '';
    unset($_GET['vnp_SecureHash']); // Loại bỏ để tính lại hash
    unset($_GET['vnp_SecureHashType']); // Loại bỏ để tính lại hash

    ksort($_GET); // Sắp xếp các tham số theo thứ tự alphabet
    $hashDataString = "";
    $i = 0;
    foreach ($_GET as $key => $value) {
        $hashDataString .= ($i == 0 ? '' : '&') . urlencode((string)$key) . "=" . urlencode((string)$value);
        $i = 1;
    }

    $secureHash = hash_hmac('sha512', $hashDataString, $vnp_HashSecret);

    // Bắt đầu transaction
    $conn->begin_transaction();

    if ($vnp_SecureHash === $secureHash) {
        $payment_id_ref = $_GET['vnp_TxnRef'] ?? null; // Đây là payment_id từ DB c
        $vnp_ResponseCode = $_GET['vnp_ResponseCode'] ?? '99';
        $vnp_TransactionStatus = $_GET['vnp_TransactionStatus'] ?? '99';
        $vnp_Amount_vnpay = ($_GET['vnp_Amount'] ?? 0) / 100; // Số tiền từ VNPAY, chia 100 để về VND
        $vnp_OrderInfo = $_GET['vnp_OrderInfo'] ?? 'Không có thông tin đơn hàng';
        $vnp_BankCode = $_GET['vnp_BankCode'] ?? ''; // Mã ngân hàng
        $vnp_CardType = $_GET['vnp_CardType'] ?? ''; // Loại thẻ
        $vnp_PayDate = $_GET['vnp_PayDate'] ?? ''; // Thời gian thanh toán VNPAY (YYYYMMDDHHIISS)
        $vnp_TransactionNo = $_GET['vnp_TransactionNo'] ?? ''; // Mã giao dịch tại VNPAY

        // Lấy thông tin thanh toán từ DB, KHÓA HÀNG ĐỂ TRÁNH RACE CONDITION VỚI RETURN_URL
        $stmtPayment = $conn->prepare("SELECT payment_id, appointment_id, total_price, status FROM payment WHERE payment_id = ? FOR UPDATE");
        if (!$stmtPayment) {
            throw new Exception("Lỗi chuẩn bị câu lệnh (IPN payment): " . $conn->error);
        }
        $stmtPayment->bind_param("i", $payment_id_ref);
        $stmtPayment->execute();
        $resultPayment = $stmtPayment->get_result();
        $paymentData = $resultPayment->fetch_assoc();
        $stmtPayment->close();

        if ($paymentData) {
            $currentPaymentStatus = $paymentData['status'];
            $appointment_id = $paymentData['appointment_id'];
            $total_price_db = $paymentData['total_price'];

            // Chỉ xử lý nếu trạng thái thanh toán đang là chờ VNPAY hoặc chưa thanh toán (0)
            // Tránh xử lý lại các giao dịch đã hoàn tất thành công (status = 1)
            if ($currentPaymentStatus == 7 || $currentPaymentStatus == 0) {
                if ($vnp_ResponseCode == '00' && $vnp_TransactionStatus == '00') {
                    // Giao dịch thành công
                    if ($total_price_db == $vnp_Amount_vnpay) { // Kiểm tra số tiền khớp
                        $new_payment_status = 1; // 1: Thành công
                        $new_appointment_status = 7; // Ví dụ: 2 là "Đã thanh toán"
                        $returnData['RspCode'] = '00'; // Thành công
                        $returnData['Message'] = 'Confirm Success';
                        write_log("IPN: Payment SUCCESS for payment_id: $payment_id_ref");
                    } else {
                        // Số tiền không khớp (có thể do lỗi hoặc gian lận)
                        $new_payment_status = 2; // 2: Lỗi
                        $new_appointment_status = 6; // Ví dụ: 6 là "Thanh toán lỗi"
                        $returnData['RspCode'] = '04'; // Số tiền không hợp lệ
                        $returnData['Message'] = 'Invalid amount';
                        write_log("IPN: Invalid amount for payment_id: $payment_id_ref. DB: $total_price_db, VNPAY: $vnp_Amount_vnpay");
                    }
                } else {
                    // Giao dịch thất bại hoặc bị hủy
                    $new_payment_status = 2; // 2: Thất bại
                    $new_appointment_status = 6; // Ví dụ: 6 là "Thanh toán lỗi"
                    $returnData['RspCode'] = '01'; // Giao dịch không thành công
                    $returnData['Message'] = 'Order Failed';
                    write_log("IPN: Payment failed for payment_id: $payment_id_ref. VNPAY ResponseCode: $vnp_ResponseCode, TransactionStatus: $vnp_TransactionStatus");
                }

                // Cập nhật trạng thái trong bảng `payment`
                $sqlUpdatePayment = "UPDATE payment SET status = ?, form = 1, payment_date = NOW()  WHERE payment_id = ?";
                $stmtUpdatePayment = $conn->prepare($sqlUpdatePayment);
                if ($stmtUpdatePayment) {
                    $stmtUpdatePayment->bind_param("ii", $new_payment_status, $payment_id_ref);
                    $stmtUpdatePayment->execute();
                    $stmtUpdatePayment->close();
                } else {
                    throw new Exception("Lỗi chuẩn bị câu lệnh (IPN update payment failed): " . $conn->error);
                }   
                
                // Cập nhật trạng thái trong bảng `payment_infor`
                $sqlUpdatepayment_infor = "UPDATE payment_infor SET status = ?, vnp_ResponseCode = ?, vnp_TransactionStatus = ?, vnp_OrderInfo = ?, vnp_BankCode = ?, vnp_CardType = ?, vnp_PayDate = ?, vnp_TransactionNo = ? WHERE payment_id = ?";
                $stmtUpdatepayment_infor = $conn->prepare($sqlUpdatepayment_infor);
                if ($stmtUpdatepayment_infor) {
                    $stmtUpdatepayment_infor->bind_param("isssssssi", $new_payment_status, $vnp_ResponseCode, $vnp_TransactionStatus, $vnp_OrderInfo, $vnp_BankCode, $vnp_CardType, $vnp_PayDate, $vnp_TransactionNo, $payment_id_ref);
                    $stmtUpdatepayment_infor->execute();
                    $stmtUpdatepayment_infor->close();
                } else {
                    throw new Exception("Lỗi chuẩn bị câu lệnh (IPN update payment_infor failed): " . $conn->error);
                }   

                // Cập nhật trạng thái trong bảng `appointment`
                $sqlUpdateAppointment = "UPDATE appointment SET status = ? WHERE appointment_id = ?";
                $stmtUpdateAppointment = $conn->prepare($sqlUpdateAppointment);
                if ($stmtUpdateAppointment) {
                    $stmtUpdateAppointment->bind_param("ii", $new_appointment_status, $appointment_id);
                    $stmtUpdateAppointment->execute();
                    $stmtUpdateAppointment->close();
                } else {
                    throw new Exception("Lỗi chuẩn bị câu lệnh (IPN update appointment failed): " . $conn->error);
                }
            } else {
                // Đơn hàng đã được xử lý bởi return_url hoặc ở trạng thái khác
                write_log("IPN: Payment record for $payment_id_ref is in an unhandleable state: " . $currentPaymentStatus . ". Returning 02.");
                $returnData['RspCode'] = '02'; // Coi như đã xử lý hoặc không cần xử lý lại
                $returnData['Message'] = 'Order already processed';
            }
        } else {
            write_log("IPN: Payment record NOT FOUND for vnp_TxnRef: " . ($payment_id_ref ?? 'NULL') . ". Returning 01.");
            $returnData['RspCode'] = '01'; // Order not found in DB
            $returnData['Message'] = 'Order not found';
        }

        $conn->commit(); // Hoàn thành giao dịch

    } else {
        write_log("IPN Signature INVALID. Received: $vnp_SecureHash, Expected: $secureHash. Returning 97.");
        $returnData['RspCode'] = '97'; // Invalid signature
        $returnData['Message'] = 'Invalid signature';
    }
} catch (Exception $e) {
    $conn->rollback(); // Rollback nếu có lỗi
    error_log("Lỗi CSDL trong IPN ($log_file): " . $e->getMessage());
    write_log("IPN System error: " . $e->getMessage() . ". Returning 99.");
    $returnData['RspCode'] = '99';
    $returnData['Message'] = 'System error: ' . $e->getMessage();
}

echo json_encode($returnData);
exit;
?>