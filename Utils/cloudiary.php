<?php
require 'vendor/autoload.php';

// Cấu hình Cloudinary
\Cloudinary\Configuration\Configuration::instance([
    'cloud' => [
        'cloud_name' => 'duemkqxyp', 
        'api_key' => '651935565868957', 
        'api_secret' => 'HrmkKLcNGmJyJtqgZ5H-Dtn_COg'
    ],
    'url' => [
        'secure' => true
    ]
]);

// API endpoint để xử lý upload ảnh
function processCloudinaryUpload() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (isset($data['publicId'])) {
        // Lưu publicId vào database
        $publicId = $data['publicId'];
        
        // Ví dụ code lưu vào database
        $db->query("UPDATE users SET avatar = '$publicId' WHERE uid = {$data['uid']}");
        
        // Trả về URL đầy đủ (tùy chọn)
        $url = \Cloudinary\Asset\Media::fromParams($publicId)->toUrl();
        
        echo json_encode([
            'status' => 'success',
            'data' => [
                'publicId' => $publicId,
                'url' => $url
            ]
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Missing publicId'
        ]);
    }
}

// Hàm lấy thông tin ảnh từ Cloudinary
function getImageInfo($publicId) {
    try {
        $api = new \Cloudinary\Api\Admin\AdminApi();
        $info = $api->asset($publicId);
        return $info;
    } catch (Exception $e) {
        return null;
    }
}