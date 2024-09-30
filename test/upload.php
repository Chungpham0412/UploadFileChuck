<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 1);
ini_set('error_log', 'error_log.txt'); // Đường dẫn tới file log lỗi
// error_reporting(E_ALL);
try {
    $uploadDir = 'uploads/';
    $chunkIndex = isset($_POST['chunkIndex']) ? (int)$_POST['chunkIndex'] : 0;
    $totalChunks = isset($_POST['totalChunks']) ? (int)$_POST['totalChunks'] : 0;

    if (!isset($_FILES['file'])) {
        throw new Exception('No file uploaded');
    }

    $file = $_FILES['file']['tmp_name'];
    $fileSize = $_FILES['file']['size'];
    $fileType = $_POST['fileType'] ?? '';
    $fileName = $_FILES['file']['name'];

    $allowedTypes = ['audio/mp3', 'audio/mpeg', 'video/mp4'];
    $maxFileSize = 1024 * 1024 * 1024;

    // Kiểm tra kích thước file
    if ($fileSize > $maxFileSize) {
        throw new Exception('File too large');
    }

    // Kiểm tra loại file hợp lệ
    if (!in_array($fileType, $allowedTypes)) {
        throw new Exception('Invalid file type');
    }

    // Tạo thư mục upload nếu chưa tồn tại
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $originalName = 'file_temp_' . $chunkIndex;
    $targetPath = $uploadDir . $originalName;

    if (!move_uploaded_file($file, $targetPath)) {
        throw new Exception('Failed to upload chunk');
    }

    // Hợp nhất file khi upload xong tất cả chunk
    if ($chunkIndex === $totalChunks - 1) {
        $finalFilePath = $uploadDir . 'final_file_name.' . 'mp4';
        $finalFile = fopen($finalFilePath, 'wb');

        if (!$finalFile) {
            throw new Exception('Failed to create final file');
        }

        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkFilePath = $uploadDir . 'file_temp_' . $i;
            if (!file_exists($chunkFilePath)) {
                throw new Exception('Chunk file does not exist');
            }

            $chunkFile = fopen($chunkFilePath, 'rb');
            if (!$chunkFile) {
                throw new Exception('Failed to open chunk file');
            }

            while ($buffer = fread($chunkFile, 4096)) {
                fwrite($finalFile, $buffer);
            }

            fclose($chunkFile);
            unlink($chunkFilePath);
        }

        fclose($finalFile);
        echo json_encode(['status' => 'success', 'message' => 'File uploaded and merged']);
    } else {
        echo json_encode(['status' => 'in_progress', 'message' => 'Chunk uploaded']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    error_log($e->getMessage()); // Ghi log lỗi vào file
}
