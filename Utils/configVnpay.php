<?php
$vnp_TmnCode = "L2FDII78"; // Mã Website (từ email của VNPAY)
$vnp_HashSecret = "HYOS87N59WX18JSOCKXB9HMNAOE7G3L7"; // Chuỗi bí mật (từ email của VNPAY)
$vnp_Url = "https://sandbox.vnpayment.vn/paymentv2/vpcpay.html"; // URL Test (từ email của VNPAY)

// Cần CHẮC CHẮN rằng vnp_Returnurl này trỏ đến file return_url.php của bạn
// Đây là URL mà trình duyệt/WebView sẽ redirect về sau khi thanh toán trên cổng VNPAY
$vnp_Returnurl = "https://wecarmih.loca.lt/apihm/Utils/return_url.php";
// Các biến động như $vnp_IpAddr, $vnp_TxnRef, $vnp_OrderInfo, $vnp_OrderType KHÔNG THUỘC VỀ FILE NÀY
// Nếu bạn thấy chúng ở đây, hãy xóa chúng đi.
?>