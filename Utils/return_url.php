<?php

require_once __DIR__ .'/../Utils/configVnpay.php';
require_once __DIR__ .'/../Config/connectdb.php'; 
require_once __DIR__ .'/../Utils/ipn_listener.php';

// === Cấu hình hiển thị lỗi (chỉ trong môi trường phát triển) ===
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

header("Content-Type: text/html; charset=utf-8");
$conn = getDBConnection();
// Dữ liệu sẽ trả về cho Flutter qua Deep Link
$dataForReactNative = [
    'outcome' => 'failed',
    'message' => 'Có lỗi xảy ra trong quá trình xử lý thanh toán.',
    'payment_id' => '',
    'amount' => 0,
    'vnp_ResponseCode' => '99',
    'vnp_TransactionStatus' => '99',
    'vnp_OrderInfo' => '',
    'appointment_id' => null,
    'uid' => null,
];

$queryParams = $_GET; // Lấy tất cả tham số từ URL
$vnp_SecureHash_received = $queryParams['vnp_SecureHash'] ?? ''; // Lấy chữ ký nhận được

// Loại bỏ các tham số không cần thiết để tính hash
if (isset($queryParams['vnp_SecureHashType'])) unset($queryParams['vnp_SecureHashType']);
if (isset($queryParams['vnp_SecureHash'])) unset($queryParams['vnp_SecureHash']);
ksort($queryParams);

$hashDataString = "";
$i = 0;
foreach ($queryParams as $key => $value) {
    $hashDataString .= ($i == 0 ? '' : '&') . urlencode((string)$key) . "=" . urlencode((string)$value);
    $i = 1;
}

// Lấy hash secret từ configVnpay.php
$secureHash = hash_hmac('sha512', $hashDataString, $vnp_HashSecret);

// Bắt đầu transaction
$conn->begin_transaction();

try {
    if ($vnp_SecureHash_received === $secureHash) {
        $payment_id_ref = $queryParams['vnp_TxnRef'] ?? null; // Đây là payment_id từ DB
        $vnp_ResponseCode = $queryParams['vnp_ResponseCode'] ?? '99';
        $vnp_TransactionStatus = $queryParams['vnp_TransactionStatus'] ?? '99';
        $vnp_Amount_vnpay = ($queryParams['vnp_Amount'] ?? 0) / 100; // Số tiền từ VNPAY, chia 100 để về VND
        $vnp_OrderInfo = $queryParams['vnp_OrderInfo'] ?? 'Không có thông tin đơn hàng';
        $vnp_BankCode = $queryParams['vnp_BankCode'] ?? ''; // Mã ngân hàng
        $vnp_CardType = $queryParams['vnp_CardType'] ?? ''; // Loại thẻ
        $vnp_PayDate = $queryParams['vnp_PayDate'] ?? ''; // Thời gian thanh toán VNPAY (YYYYMMDDHHIISS)
        $vnp_TransactionNo = $queryParams['vnp_TransactionNo'] ?? ''; // Mã giao dịch tại VNPAY

        // Lấy thông tin thanh toán từ DB, KHÓA HÀNG ĐỂ TRÁNH RACE CONDITION VỚI IPN
        $stmtPayment = $conn->prepare("SELECT payment_id, appointment_id, total_price, status, uid FROM payment WHERE payment_id = ? FOR UPDATE");
        if (!$stmtPayment) {
            throw new Exception("Lỗi chuẩn bị câu lệnh (payment): " . $conn->error);
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
            $uid_db = $paymentData['uid']; // Lấy UID từ DB

            // Cập nhật dữ liệu cho Flutter
            $dataForReactNative['payment_id'] = $payment_id_ref;
            $dataForReactNative['amount'] = $total_price_db; // Sử dụng số tiền từ DB
            $dataForReactNative['vnp_ResponseCode'] = $vnp_ResponseCode;
            $dataForReactNative['vnp_TransactionStatus'] = $vnp_TransactionStatus;
            $dataForReactNative['vnp_OrderInfo'] = $vnp_OrderInfo;
            $dataForReactNative['appointment_id'] = $appointment_id;
            $dataForReactNative['uid'] = $uid_db; // Gửi UID về Flutter

            // Chỉ xử lý nếu trạng thái thanh toán đang là chờ VNPAY (7) hoặc chưa thanh toán (0)
            if ($currentPaymentStatus == 0) {
                if ($vnp_ResponseCode == '00' && $vnp_TransactionStatus == '00') {
                    // Giao dịch thành công
                    if ($total_price_db == $vnp_Amount_vnpay) { // Kiểm tra số tiền khớp
                        $new_payment_status = 1; // 1: Thành công
                        $new_appointment_status = 7; // Ví dụ: 2 là "Đã thanh toán"
                        $dataForReactNative['outcome'] = 'success';
                        $dataForReactNative['message'] = 'Thanh toán thành công!';
                    } else {
                        // Số tiền không khớp (có thể do lỗi hoặc gian lận)
                        $new_payment_status = 2; // 2: Lỗi
                        $new_appointment_status = 6; // Ví dụ: 6 là "Thanh toán lỗi"
                        $dataForReactNative['outcome'] = 'failed';
                        $dataForReactNative['message'] = 'Lỗi: Số tiền không khớp giữa VNPAY và hệ thống.';
                    }
                } else {
                    // Giao dịch thất bại hoặc bị hủy
                    $new_payment_status = 2; // 2: Thất bại
                    $new_appointment_status = 6; // Ví dụ: 6 là "Thanh toán lỗi"
                    $dataForReactNative['outcome'] = 'failed';
                    $dataForReactNative['message'] = 'Thanh toán thất bại hoặc đã hủy. Mã lỗi VNPAY: ' . $vnp_ResponseCode;
                }
                
                // Cập nhật trạng thái trong bảng `payment`
                $sqlUpdatePayment = "UPDATE payment SET status = ?, form = 1, payment_date = NOW()  WHERE payment_id = ?";
                $stmtUpdatePayment = $conn->prepare($sqlUpdatePayment);
                if (!$stmtUpdatePayment) throw new Exception("Lỗi chuẩn bị câu lệnh (update payment): " . $conn->error);
                $stmtUpdatePayment->bind_param("ii", $new_payment_status, $payment_id_ref);
                $stmtUpdatePayment->execute();
                $stmtUpdatePayment->close();

                // Cập nhật trạng thái trong bảng `payment_infor`
                $sqlUpdatepayment_infor = "UPDATE payment_infor SET status = ?, vnp_ResponseCode = ?, vnp_TransactionStatus = ?, vnp_OrderInfo = ?, vnp_BankCode = ?, vnp_CardType = ?, vnp_PayDate = ?, vnp_TransactionNo = ? WHERE payment_id = ?";
                $stmtUpdatepayment_infor = $conn->prepare($sqlUpdatepayment_infor);
                if (!$stmtUpdatepayment_infor) throw new Exception("Lỗi chuẩn bị câu lệnh (update payment): " . $conn->error);
                $stmtUpdatepayment_infor->bind_param("isssssssi", $new_payment_status, $vnp_ResponseCode, $vnp_TransactionStatus, $vnp_OrderInfo, $vnp_BankCode, $vnp_CardType, $vnp_PayDate, $vnp_TransactionNo, $payment_id_ref);
                $stmtUpdatepayment_infor->execute();
                $stmtUpdatepayment_infor->close();

                // Cập nhật trạng thái trong bảng `appointment`
                $sqlUpdateAppointment = "UPDATE appointment SET status = ? WHERE appointment_id = ?";
                $stmtUpdateAppointment = $conn->prepare($sqlUpdateAppointment);
                if (!$stmtUpdateAppointment) throw new Exception("Lỗi chuẩn bị câu lệnh (update appointment): " . $conn->error);
                $stmtUpdateAppointment->bind_param("ii", $new_appointment_status, $appointment_id);
                $stmtUpdateAppointment->execute();
                $stmtUpdateAppointment->close();

            } else {
                // Đơn hàng đã được xử lý bởi IPN hoặc ở trạng thái không cần xử lý lại
                $dataForReactNative['outcome'] = 'processed';
                $dataForReactNative['message'] = 'Đơn hàng đã được xử lý trước đó hoặc ở trạng thái không hợp lệ.';
                // Không thay đổi trạng thái nếu nó không phải là 0 hoặc 7
                if ($currentPaymentStatus == 1) { // Đã thành công rồi
                    $dataForReactNative['outcome'] = 'success';
                    $dataForReactNative['message'] = 'Thanh toán đã hoàn tất thành công.';
                }
            }
        } else {
            // Không tìm thấy bản ghi thanh toán
            $dataForReactNative['message'] = 'Không tìm thấy bản ghi thanh toán với ID: ' . ($payment_id_ref ?? 'NULL');
        }

        $conn->commit(); // Hoàn thành giao dịch

    } else {
        // Chữ ký không hợp lệ
        $dataForReactNative['message'] = 'Chữ ký không hợp lệ. Giao dịch có thể không an toàn.';
    }
} catch (Exception $e) {
    $conn->rollback(); // Rollback nếu có lỗi
    error_log("Lỗi CSDL trong return_url.php: " . $e->getMessage());
    $dataForReactNative['message'] = 'Lỗi hệ thống: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Kết quả thanh toán</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: sans-serif; text-align: center; padding-top: 50px; background-color: #f0f0f0; }
        .success { color: green; }
        .failed { color: red; }
        .processed { color: orange; } /* Thêm màu cho trạng thái đã xử lý */
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