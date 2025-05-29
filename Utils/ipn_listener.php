<?php
require_once __DIR__ .'/../Config/connectdb.php'; 
require_once __DIR__ .'/../Utils/configVnpay.php';

// === Cấu hình hiển thị lỗi (chỉ trong môi trường phát triển) ===
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

header("Content-Type: application/json"); // Trả về JSON cho VNPAY

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
    $hashData = "";
    foreach ($_GET as $key => $value) {
        $hashData .= urlencode((string)$key) . '=' . urlencode((string)$value) . '&';
    }
    $hashData = rtrim($hashData, '&'); // Xóa ký tự '&' cuối cùng

    $secureHash = hash_hmac('sha512', $hashData, $vnp_HashSecret);

    if ($secureHash == $vnp_SecureHash) {
        // Lấy các tham số VNPAY
        $vnp_ResponseCode = $_GET['vnp_ResponseCode'] ?? '';
        $vnp_TxnRef = $_GET['vnp_TxnRef'] ?? ''; // Mã giao dịch của bạn
        $vnp_Amount = $_GET['vnp_Amount'] ?? 0;
        $vnp_TransactionNo = $_GET['vnp_TransactionNo'] ?? ''; // Mã giao dịch bên VNPAY
        $vnp_BankCode = $_GET['vnp_BankCode'] ?? '';
        $vnp_PayDate = $_GET['vnp_PayDate'] ?? '';
        $vnp_OrderInfo = $_GET['vnp_OrderInfo'] ?? '';
        $vnp_TransactionStatus = $_GET['vnp_TransactionStatus'] ?? '';


        write_log("IPN Checksum MATCHED. vnp_TxnRef: $vnp_TxnRef, ResponseCode: $vnp_ResponseCode, Amount: $vnp_Amount");

        $conn->begin_transaction();

        // PHÂN BIỆT LOẠI GIAO DỊCH DỰA VÀO vnp_TxnRef (tiền tố 'DP' cho đặt cọc)
        if (strpos($vnp_TxnRef, 'DP') === 0) {
            // Đây là giao dịch đặt cọc
            $deposit_id_ref = (int) substr($vnp_TxnRef, 2); // Bỏ tiền tố 'DP'
            write_log("Detected DEPOSIT transaction. Deposit ID: $deposit_id_ref");

            // Lấy thông tin đặt cọc từ DB
            $sql_select_deposit = "SELECT status, amount FROM deposits WHERE deposit_id = ? FOR UPDATE"; // FOR UPDATE để khóa bản ghi
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
                $stmt_select_deposit->close();

                // Kiểm tra trạng thái hiện tại của đơn hàng và số tiền
                // So sánh số tiền để tránh giả mạo
                if ($deposit_amount_in_db * 100 != $vnp_Amount) {
                    write_log("IPN: Deposit amount mismatch. DB: $deposit_amount_in_db, VNPAY: " . ($vnp_Amount / 100));
                    $returnData['RspCode'] = '04'; // Invalid amount
                    $returnData['Message'] = 'Invalid Amount';
                    $conn->rollback(); // Rollback giao dịch
                    echo json_encode($returnData);
                    exit;
                }

                // Chỉ xử lý nếu trạng thái là 0 (chưa xử lý)
                if ($currentDepositStatus == 0) {
                    $update_deposit_status = 2; // Mặc định là thất bại
                    $update_appointment_status = null; // Không thay đổi nếu thất bại

                    if ($vnp_ResponseCode == '00' && $vnp_TransactionStatus == '00') {
                        // Giao dịch đặt cọc thành công
                        $update_deposit_status = 1; // Đã thanh toán
                        $update_appointment_status = 2; // Cập nhật trạng thái appointment sang 2 (đã đặt cọc)
                        write_log("IPN: Deposit ID $deposit_id_ref SUCCESS. Updating deposit status to 1 and appointment status to 2.");
                        $returnData['RspCode'] = '00'; // Giao dịch thành công
                        $returnData['Message'] = 'Confirm Success';
                    } else {
                        // Giao dịch đặt cọc thất bại hoặc bị hủy
                         $update_deposit_status = 0; // Thất bại/Hủy
                        write_log("IPN: Deposit ID $deposit_id_ref FAILED/CANCELLED. ResponseCode: $vnp_ResponseCode, TransactionStatus: $vnp_TransactionStatus. Updating deposit status to 2.");
                        $returnData['RspCode'] = '01'; // Giao dịch không thành công
                        $returnData['Message'] = 'Order Failed';
                    }

                    // Cập nhật trạng thái bản ghi đặt cọc trong DB
                    $sql_update_deposit = "UPDATE deposits SET status = ?, deposit_date = NOW(), vnpay_transaction_id = ?, vnpay_response_code = ?, vnpay_transaction_status = ? WHERE deposit_id = ?";
                    $stmt_update_deposit = $conn->prepare($sql_update_deposit);
                    if (!$stmt_update_deposit) {
                        throw new Exception("Lỗi chuẩn bị câu lệnh UPDATE deposit: " . $conn->error);
                    }
                    $stmt_update_deposit->bind_param("isssi", $update_deposit_status, $vnp_TransactionNo, $vnp_ResponseCode, $vnp_TransactionStatus, $deposit_id_ref);
                    if (!$stmt_update_deposit->execute()) {
                        throw new Exception("Lỗi thực thi câu lệnh UPDATE deposit: " . $stmt_update_deposit->error);
                    }
                    $stmt_update_deposit->close();

                    // Nếu đặt cọc thành công, cập nhật trạng thái appointment
                    if ($update_appointment_status !== null) {
                        // Lấy appointment_id từ bản ghi deposit
                        $sql_get_appointment_id = "SELECT appointment_id FROM deposits WHERE deposit_id = ?";
                        $stmt_get_appointment_id = $conn->prepare($sql_get_appointment_id);
                        $stmt_get_appointment_id->bind_param("i", $deposit_id_ref);
                        $stmt_get_appointment_id->execute();
                        $result_app_id = $stmt_get_appointment_id->get_result();
                        $app_data = $result_app_id->fetch_assoc();
                        $appointment_id_from_deposit = $app_data['appointment_id'];
                        $stmt_get_appointment_id->close();

                        if ($appointment_id_from_deposit) {
                            $sql_update_appointment = "UPDATE appointment SET status = ? WHERE appointment_id = ?";
                            $stmt_update_appointment = $conn->prepare($sql_update_appointment);
                            if (!$stmt_update_appointment) {
                                throw new Exception("Lỗi chuẩn bị câu lệnh UPDATE appointment (deposit): " . $conn->error);
                            }
                            $stmt_update_appointment->bind_param("ii", $update_appointment_status, $appointment_id_from_deposit);
                            if (!$stmt_update_appointment->execute()) {
                                throw new Exception("Lỗi thực thi câu lệnh UPDATE appointment (deposit): " . $stmt_update_appointment->error);
                            }
                            $stmt_update_appointment->close();
                            write_log("IPN: Appointment ID $appointment_id_from_deposit status updated to $update_appointment_status (deposit success).");
                        } else {
                            write_log("IPN: Could not find appointment_id for deposit_id: $deposit_id_ref to update appointment status.");
                        }
                    }

                } else {
                    // Đơn hàng đặt cọc đã được xử lý bởi return_url hoặc ở trạng thái khác
                    write_log("IPN: Deposit record for DP$deposit_id_ref is in an unhandleable state: " . $currentDepositStatus . ". Returning 02.");
                    $returnData['RspCode'] = '02'; // Coi như đã xử lý hoặc không cần xử lý lại
                    $returnData['Message'] = 'Order already processed';
                }
            } else {
                write_log("IPN: Deposit record NOT FOUND for vnp_TxnRef: " . $vnp_TxnRef . ". Returning 01.");
                $returnData['RspCode'] = '01'; // Order not found in DB
                $returnData['Message'] = 'Order not found';
            }

        } else {
            // Đây là giao dịch thanh toán đầy đủ (payment) - GIỮ NGUYÊN LOGIC CŨ CỦA BẠN
            $payment_id_ref = (int) $vnp_TxnRef;
            write_log("Detected FULL PAYMENT transaction. Payment ID: $payment_id_ref");

            // Lấy thông tin thanh toán từ DB (giữ nguyên code cũ của bạn)
            $sql_select_payment = "SELECT status, total_price FROM payment WHERE payment_id = ? FOR UPDATE";
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
                $stmt_select_payment->close();

                // So sánh số tiền để tránh giả mạo
                if ($payment_amount_in_db * 100 != $vnp_Amount) {
                    write_log("IPN: Payment amount mismatch. DB: $payment_amount_in_db, VNPAY: " . ($vnp_Amount / 100));
                    $returnData['RspCode'] = '04'; // Invalid amount
                    $returnData['Message'] = 'Invalid Amount';
                    $conn->rollback();
                    echo json_encode($returnData);
                    exit;
                }

                if ($currentPaymentStatus == 0) { // Chỉ xử lý nếu trạng thái là 0 (chưa xử lý)
                    $update_payment_status = -1; // Mặc định là thất bại
                    $update_appointment_status = null; // Không thay đổi nếu thất bại

                    if ($vnp_ResponseCode == '00' && $vnp_TransactionStatus == '00') {
                        // Giao dịch thanh toán đầy đủ thành công
                        $update_payment_status = 1; // Đã thanh toán
                      
                        $update_appointment_status = 7; // Ví dụ: 7 là 'đã thanh toán đầy đủ'
                        write_log("IPN: Payment ID $payment_id_ref SUCCESS. Updating payment status to 1 and appointment status to 7.");
                        $returnData['RspCode'] = '00'; // Giao dịch thành công
                        $returnData['Message'] = 'Confirm Success';
                    } else {
                        // Giao dịch thanh toán đầy đủ thất bại
                        $update_payment_status = 2; // Thất bại
                        write_log("IPN: Payment ID $payment_id_ref FAILED. ResponseCode: $vnp_ResponseCode, TransactionStatus: $vnp_TransactionStatus. Updating payment status to 2.");
                        $returnData['RspCode'] = '01'; // Giao dịch không thành công
                        $returnData['Message'] = 'Order Failed';
                    }

                    // Cập nhật trạng thái bản ghi thanh toán trong DB
                    $sql_update_payment = "UPDATE payment SET status = ?, form = 1, payment_date = NOW()  WHERE payment_id = ?";
                    $stmt_update_payment = $conn->prepare($sql_update_payment);
                    if (!$stmt_update_payment) {
                        throw new Exception("Lỗi chuẩn bị câu lệnh UPDATE payment: " . $conn->error);
                    }
                    $stmt_update_payment->bind_param("ii", $update_payment_status, $payment_id_ref);
                    if (!$stmt_update_payment->execute()) {
                        throw new Exception("Lỗi thực thi câu lệnh UPDATE payment: " . $stmt_update_payment->error);
                    }
                    $stmt_update_payment->close();

                    // Nếu thanh toán đầy đủ thành công, cập nhật trạng thái appointment
                    if ($update_appointment_status !== null) {
                        // Lấy appointment_id từ bản ghi payment
                        $sql_get_appointment_id = "SELECT appointment_id FROM payment WHERE payment_id = ?";
                        $stmt_get_appointment_id = $conn->prepare($sql_get_appointment_id);
                        $stmt_get_appointment_id->bind_param("i", $payment_id_ref);
                        $stmt_get_appointment_id->execute();
                        $result_app_id = $stmt_get_appointment_id->get_result();
                        $app_data = $result_app_id->fetch_assoc();
                        $appointment_id_from_payment = $app_data['appointment_id'];
                        $stmt_get_appointment_id->close();

                        if ($appointment_id_from_payment) {
                            $sql_update_appointment = "UPDATE appointment SET status = ? WHERE appointment_id = ?";
                            $stmt_update_appointment = $conn->prepare($sql_update_appointment);
                            if (!$stmt_update_appointment) {
                                throw new Exception("Lỗi chuẩn bị câu lệnh UPDATE appointment (payment): " . $conn->error);
                            }
                            $stmt_update_appointment->bind_param("ii", $update_appointment_status, $appointment_id_from_payment);
                            if (!$stmt_update_appointment->execute()) {
                                throw new Exception("Lỗi thực thi câu lệnh UPDATE appointment (payment): " . $stmt_update_appointment->error);
                            }
                            $stmt_update_appointment->close();
                            write_log("IPN: Appointment ID $appointment_id_from_payment status updated to $update_appointment_status (full payment success).");
                        } else {
                            write_log("IPN: Could not find appointment_id for payment_id: $payment_id_ref to update appointment status.");
                        }
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
    write_log("IPN System error: " . $e->getMessage());
    $returnData['RspCode'] = '99';
    $returnData['Message'] = 'System error: ' . $e->getMessage();
} finally {
    if ($conn) {
        $conn->close();
    }
    echo json_encode($returnData);
}
?>