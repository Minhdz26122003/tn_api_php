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
    die(
        json_encode([
            "success" => false,
            "message" => "Lỗi kết nối cơ sở dữ liệu: " . $conn->connect_error,
        ])
    );
}

// Lấy token từ header
$headers = getallheaders();
$token = $headers["Authorization"] ?? "";

// Xác thực token
if (!verifyToken($token)) {
    echo json_encode([
        "success" => false,
        "message" => "Token không hợp lệ hoặc đã hết hạn",
    ]);
    $conn->close();
    exit();
}

// Lấy dữ liệu từ request body
$data = json_decode(file_get_contents("php://input"), true);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (
        isset($data["appointment_id"]) &&
        isset($data["parts"]) &&
        is_array($data["parts"])
    ) {
        $appointment_id = $data["appointment_id"];
        $parts = $data["parts"];

        // Kiểm tra sự tồn tại của appointment_id
        $stmt_check_appointment = $conn->prepare(
            "SELECT appointment_id FROM appointment WHERE appointment_id = ?"
        );
        $stmt_check_appointment->bind_param("i", $appointment_id);
        $stmt_check_appointment->execute();
        $result_check_appointment = $stmt_check_appointment->get_result();

        if ($result_check_appointment->num_rows === 0) {
            echo json_encode([
                "success" => false,
                "message" => "Mã lịch hẹn không tồn tại.",
            ]);
            $stmt_check_appointment->close();
            $conn->close();
            exit();
        }
        $stmt_check_appointment->close();

        $conn->begin_transaction();
        $error_occurred = false;

        foreach ($parts as $part) {
            if (
                isset($part["id"], $part["quantity"]) &&
                is_numeric($part["quantity"]) &&
                $part["quantity"] > 0
            ) {
                $accessory_id = $part["id"];
                $quantity_to_add = $part["quantity"];

                // --- Kiểm tra accessory tồn tại ---
                $stmt_check_accessory = $conn->prepare(
                    "SELECT accessory_id FROM accessory WHERE accessory_id = ?"
                );
                $stmt_check_accessory->bind_param("i", $accessory_id);
                $stmt_check_accessory->execute();
                $res_acc = $stmt_check_accessory->get_result();
                $stmt_check_accessory->close();

                if ($res_acc->num_rows === 0) {
                    echo json_encode([
                        "success" => false,
                        "message" => "Mã phụ tùng '$accessory_id' không tồn tại.",
                        "invalid_part_id" => $accessory_id,
                    ]);
                    $conn->rollback();
                    $error_occurred = true;
                    break;
                }

                // --- Kiểm tra xem đã có hóa đơn cho phụ tùng này chưa ---
                $stmt_check_payment = $conn->prepare(
                    "SELECT quantity FROM accessory_payment 
             WHERE accessory_id = ? AND appointment_id = ? FOR UPDATE"
                );
                $stmt_check_payment->bind_param(
                    "ii",
                    $accessory_id,
                    $appointment_id
                );
                $stmt_check_payment->execute();
                $res_pay = $stmt_check_payment->get_result();
                $stmt_check_payment->close();

                if ($res_pay->num_rows > 0) {
                    // Đã có -> cập nhật quantity
                    $row = $res_pay->fetch_assoc();
                    $new_quantity = $row["quantity"] + $quantity_to_add;

                    $stmt_update = $conn->prepare(
                        "UPDATE accessory_payment 
                 SET quantity = ? 
                 WHERE accessory_id = ? AND appointment_id = ?"
                    );
                    $stmt_update->bind_param(
                        "iii",
                        $new_quantity,
                        $accessory_id,
                        $appointment_id
                    );

                    if (!$stmt_update->execute()) {
                        echo json_encode([
                            "success" => false,
                            "message" =>
                                "Lỗi khi cập nhật phụ tùng '$accessory_id': " .
                                $stmt_update->error,
                        ]);
                        $conn->rollback();
                        $error_occurred = true;
                        $stmt_update->close();
                        break;
                    }
                    $stmt_update->close();
                } else {
                    // Chưa có -> insert mới
                    $stmt_insert = $conn->prepare(
                        "INSERT INTO accessory_payment 
                 (accessory_id, appointment_id, quantity) 
                 VALUES (?, ?, ?)"
                    );
                    $stmt_insert->bind_param(
                        "iii",
                        $accessory_id,
                        $appointment_id,
                        $quantity_to_add
                    );

                    if (!$stmt_insert->execute()) {
                        echo json_encode([
                            "success" => false,
                            "message" =>
                                "Lỗi khi thêm phụ tùng '$accessory_id': " .
                                $stmt_insert->error,
                        ]);
                        $conn->rollback();
                        $error_occurred = true;
                        $stmt_insert->close();
                        break;
                    }
                    $stmt_insert->close();
                }
            } else {
                echo json_encode([
                    "success" => false,
                    "message" =>
                        "Dữ liệu phụ tùng không hợp lệ. Đảm bảo mỗi phụ tùng có 'id' và 'quantity' (> 0).",
                    "invalid_part_data" => $part,
                ]);
                $conn->rollback();
                $error_occurred = true;
                break;
            }
        }

        if (!$error_occurred) {
            $conn->commit();
            echo json_encode([
                "success" => true,
                "message" => "Đã thêm phụ tùng vào hóa đơn thành công.",
            ]);
        }
    } else {
        echo json_encode([
            "success" => false,
            "message" =>
                "Thiếu thông tin 'appointment_id' hoặc 'parts' (mảng các phụ tùng).",
        ]);
    }
} elseif ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Phương thức không được hỗ trợ. Chỉ chấp nhận POST.",
    ]);
}

$conn->close();
?>
