<?php
class ResponseHelper {
    public static function sendResponse($success, $message, $data = null) {
        echo json_encode(["success" => $success, "message" => $message, "data" => $data]);
    }

    public static function sendError($message) {
        echo json_encode(["success" => false, "message" => $message]);
    }
    
}
?>
