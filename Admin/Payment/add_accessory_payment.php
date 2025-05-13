<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

$conn = getDBConnection();

// Kiểm tra kết nối
if ($conn->connect_error) {
    die(json_encode(["success" => false, "message" => "Lỗi kết nối cơ sở dữ liệu: " . $conn->connect_error]));
}

// Lấy token từ header
$headers = getallheaders();
$token = $headers["Authorization"] ?? "";

// Xác thực token
if (!verifyToken($token)) {
    echo json_encode(["success" => false, "message" => "Token không hợp lệ hoặc đã hết hạn"]);
    $conn->close();
    exit();
}

// Lấy dữ liệu từ request body
$data = json_decode(file_get_contents("php://input"), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($data['appointment_id']) && isset($data['parts']) && is_array($data['parts'])) {
        $appointment_id = $data['appointment_id'];
        $parts = $data['parts'];

        // Kiểm tra sự tồn tại của appointment_id
        $stmt_check_appointment = $conn->prepare("SELECT appointment_id FROM appointment WHERE appointment_id = ?");
        $stmt_check_appointment->bind_param("i", $appointment_id);
        $stmt_check_appointment->execute();
        $result_check_appointment = $stmt_check_appointment->get_result();

        if ($result_check_appointment->num_rows === 0) {
            echo json_encode(["success" => false, "message" => "Mã lịch hẹn không tồn tại."]);
            $stmt_check_appointment->close();
            $conn->close();
            exit();
        }
        $stmt_check_appointment->close();

        $conn->begin_transaction();
        $error_occurred = false;

        foreach ($parts as $part) {
            if (isset($part['id']) && isset($part['quantity']) && is_numeric($part['quantity']) && $part['quantity'] > 0) {
                $accessory_id = $part['id'];
                $quantity = $part['quantity'];

                // Kiểm tra sự tồn tại của accessory_id
                $stmt_check_accessory = $conn->prepare("SELECT accessory_id FROM accessory WHERE accessory_id = ?");
                $stmt_check_accessory->bind_param("i", $accessory_id);
                $stmt_check_accessory->execute();
                $result_check_accessory = $stmt_check_accessory->get_result();

                if ($result_check_accessory->num_rows === 0) {
                    echo json_encode(["success" => false, "message" => "Mã phụ tùng '$accessory_id' không tồn tại.", 'invalid_part_id' => $accessory_id]);
                    $conn->rollback();
                    $error_occurred = true;
                    break;
                }
                $stmt_check_accessory->close();

                // Thêm phụ tùng vào accessory_payment
                $stmt_insert_payment = $conn->prepare("INSERT INTO accessory_payment (accessory_id, appointment_id, quantity) VALUES (?, ?, ?)");
                $stmt_insert_payment->bind_param("iii", $accessory_id, $appointment_id, $quantity);

                if (!$stmt_insert_payment->execute()) {
                    echo json_encode(["success" => false, "message" => "Lỗi khi thêm phụ tùng '$accessory_id' vào hóa đơn: " . $stmt_insert_payment->error]);
                    $conn->rollback();
                    $error_occurred = true;
                    $stmt_insert_payment->close();
                    break;
                }
                $stmt_insert_payment->close();

            } else {
                echo json_encode(["success" => false, "message" => "Dữ liệu phụ tùng không hợp lệ. Đảm bảo mỗi phụ tùng có 'id' và 'quantity' (lớn hơn 0).", 'invalid_part_data' => $part]);
                $conn->rollback();
                $error_occurred = true;
                break;
            }
        }

        if (!$error_occurred) {
            $conn->commit();
            echo json_encode(["success" => true, "message" => "Đã thêm phụ tùng vào hóa đơn thành công."]);
        }

    } else {
        echo json_encode(["success" => false, "message" => "Thiếu thông tin 'appointment_id' hoặc 'parts' (mảng các phụ tùng)."]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
} else {
    echo json_encode(["success" => false, "message" => "Phương thức không được hỗ trợ. Chỉ chấp nhận POST."]);
}

$conn->close();
?>