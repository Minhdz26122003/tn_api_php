<?php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token.php"; // Nếu cần xác thực token

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

$conn = getDBConnection();

if ($conn->connect_error) {
    echo json_encode(["success" => false, "message" => "Kết nối thất bại: " . $conn->connect_error]);
    exit();
}
$headers = getallheaders();
$token = $headers["Authorization"] ?? "";

// Xác thực token
if (!verifyToken($token)) {
    echo json_encode([
        "success" => false,
        "message" => "Token không hợp lệ hoặc đã hết hạn"
    ]);
    $conn->close();
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $startDate = $_GET['start_date'] ?? null; // YYYY-MM-DD
    $endDate = $_GET['end_date'] ?? null;     // YYYY-MM-DD
    $groupBy = $_GET['group_by'] ?? 'day';    // 'day', 'month', 'year'

    // Xác thực và làm sạch groupBy
    if (!in_array($groupBy, ['day', 'month', 'year'])) {
        $groupBy = 'day'; // Mặc định nếu không hợp lệ
    }

    $selectClause = "";
    $groupByClause = "";
    $orderByClause = "";

    switch ($groupBy) {
        case 'day':
            $selectClause = "DATE(appointment_date) AS time_period";
            $groupByClause = "DATE(appointment_date)";
            $orderByClause = "time_period ASC";
            break;
        case 'month':
            $selectClause = "DATE_FORMAT(appointment_date, '%Y-%m') AS time_period";
            $groupByClause = "DATE_FORMAT(appointment_date, '%Y-%m')";
            $orderByClause = "time_period ASC";
            break;
        case 'year':
            $selectClause = "YEAR(appointment_date) AS time_period";
            $groupByClause = "YEAR(appointment_date)";
            $orderByClause = "time_period ASC";
            break;
    }

    $query = "
        SELECT
            {$selectClause},
            COUNT(appointment_id) AS total_appointments
        FROM
            appointment
        WHERE 1=1
    ";

    $params = [];
    $types = "";

    if ($startDate) {
        $query .= " AND appointment_date >= ?";
        $params[] = $startDate;
        $types .= "s";
    }
    if ($endDate) {
        $query .= " AND appointment_date <= ?";
        $params[] = $endDate;
        $types .= "s";
    }

    $query .= " GROUP BY {$groupByClause} ORDER BY {$orderByClause}";

    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        echo json_encode(["success" => false, "message" => "Lỗi chuẩn bị truy vấn: " . $conn->error]);
        $conn->close();
        exit();
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode([
        "success" => true,
        "data" => $data
    ]);

    $stmt->close();
} else {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Phương thức không được hỗ trợ. Chỉ hỗ trợ GET."]);
}

$conn->close();
?>