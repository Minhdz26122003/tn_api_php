<?php
// Notify/list_notify.php
require_once "../../Config/connectdb.php";
require_once "../../Utils/function.php";
require_once "../../Utils/verify_token_user.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

checkToken();

$input = $_POST;
if (empty($input)) {
    $input = json_decode(file_get_contents('php://input'), true);
}

// Validate keyCert/time
if (!isset($input['time'], $input['keyCert'])) {
    http_response_code(400);
    exit(json_encode(["status"=>"error","error"=>["code"=>400,"message"=>"Thiếu keyCert hoặc time"]]));
}
if (!isValidKey($input['keyCert'], $input['time'])) {
    http_response_code(403);
    exit(json_encode(["status"=>"error","error"=>["code"=>403,"message"=>"keyCert không hợp lệ"]]));
}

// Validate params
$pageSize = isset($input['pageSize']) ? (int)$input['pageSize'] : 10;
$page     = isset($input['page'])     ? (int)$input['page']     : 1;
$uid      = isset($input['uid'])      ? (int)$input['uid']      : 0;
if ($uid <= 0) {
    http_response_code(400);
    exit(json_encode(["status"=>"error","error"=>["code"=>400,"message"=>"Thiếu uid hợp lệ"]]));
}

$conn = getDBConnection();
// Tính tổng
$resTotal = $conn->query("SELECT COUNT(*) AS cnt FROM notifications WHERE uid=$uid");
$totalCount = (int)$resTotal->fetch_assoc()['cnt'];
$totalPage  = ceil($totalCount / $pageSize);

// Lấy dữ liệu phân trang
$offset = ($page - 1) * $pageSize;
$stmt = $conn->prepare(
  "SELECT uid, title, body, status, time_created
   FROM notifications
   WHERE uid = ?
   ORDER BY time_created DESC
   LIMIT ? OFFSET ?"
);
$stmt->bind_param("iii", $uid, $pageSize, $offset);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = [
        'uid'          => (int)$row['uid'],
        'title'        => $row['title'],
        'body'         => $row['body'],
        'status'       => (int)$row['status'],
        'time_created' => $row['time_created'],
    ];
}
$stmt->close();
$conn->close();

echo json_encode([
  "status"=>"success",
  "error"=>["code"=>0,"message"=>"OK"],
  "data"=>[
    "pagination"=>[
      "totalCount"=>$totalCount,
      "totalPage"=>$totalPage,
      "pageSize"=>$pageSize,
      "page"=>$page
    ],
    "items"=>$items
  ]
]);
?>