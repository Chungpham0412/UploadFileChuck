<?php

namespace App\Http\Controllers\Api\File;

use App\Enums\V1\FileUpload;
use App\Enums\V1\PublicUploadsPath;
use App\Http\Controllers\Api\BaseController;
use App\Repositories\File\FileRepository;
use App\Services\MasterFileService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use Aws\S3\S3Client;

class UploadController extends BaseController
{
    protected $fileRepository;

    public function __construct()
    {
        $this->fileRepository = new FileRepository();
    }

    public function index()
    {
        try {
            $disk = MasterFileService::currentDisk();
            $files = Storage::disk($disk)->allFiles();
            return $this->sendResponse($files, trans('file.list'));
        } catch (Exception $e) {
            $this->logException($e, 'error_exception');
            return $this->apiRespondError($e);
        }
    }

    public function uploadFile(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'file' => 'required|file',
                'type' => 'required|string', // Type này là loại file, ví dụ: avatar, image, video, document, ...
            ]);
            if ($validator->fails()) {
                return $this->sendError(trans('validation.invalid'), $validator->errors(), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $file = $this->fileRepository->handleFileUpload($request->file('file'));

            $response = array(
                'disk' => $file['disk'],
                'path' => $file['path'],
                'name' => $file['name'],
                'url' => $file['url'],
                'size' => $file['size'],
                'extension' => $file['extension'],
            );

            $saveFile = array_merge($response, [
                'type' => $request->input('type'),
            ]);

            $file_info = $this->fileRepository->create($saveFile);
            return $this->sendResponse(['path' => $file_info], trans('file.upload.success'));
        } catch (Exception $e) {
            $this->logException($e, 'error_exception');
            return $this->apiRespondError($e);
        }
    }

    public function deleteFile(Request $request)
    {
        try {
            $params = ['path', 'name', 'relation_id', 'table_name'];
            $input = $request->only($params);
            $validator = Validator::make($input, [
                'path' => 'nullable|string',
                'name' => 'nullable|string',
                'relation_id' => 'nullable|integer|exists:files,relation_id',
                'table_name' => 'required_with:relation_id|string',
            ]);
            $validator->after(function ($validator) use ($input, $params) {
                if (empty($input['path']) && empty($input['name']) && empty($input['relation_id'])) {
                    $validator->errors()->add('fields', trans('validation.delete_file.required', ['attribute' => implode(', ', $params)]));
                }
            });
            if ($validator->fails()) {
                return $this->sendError(trans('validation.invalid'), $validator->errors(), Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if (isset($input['relation_id']) && isset($input['table_name'])) {
                $file = $this->fileRepository->getByRelationId($input['relation_id'], $input['table_name']);
                $disk = $file->disk;
                $path = $file->path;
            }
            if (isset($input['path'])) {
                $file = $this->fileRepository->getByPath($input['path']);
                $disk = $file->disk;
                $path = $file->path;
            }
            if (isset($input['name'])) {
                $file = $this->fileRepository->getByName($input['name']);
                $disk = $file->disk;
                $path = $file->path;
            }
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
                $this->fileRepository->deleteById($file->id);
                return $this->sendResponse([], trans('file.deleted'));
            }
        } catch (Exception $e) {
            $this->logException($e, 'error_exception');
            return $this->apiRespondError($e);
        }
    }

    public function downloadFile(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'path' => 'required|string',
            ]);
            if ($validator->fails()) {
                return $this->sendError(trans('validation.invalid'), $validator->errors(), Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $path = $request->input('path');
            $disk = MasterFileService::currentDisk();
            if (Storage::disk($disk)->exists($path)) {
                $file = Storage::disk($disk)->get($path);
                return response()->download($file, basename($path));
            }
        } catch (Exception $e) {
            $this->logException($e, 'error_exception');
            return $this->apiRespondError($e);
        }
    }

    /*
    * Function get presigned url
    * Mục đích: Lấy link tải file từ S3
    */
    public function getPresignedUrl(Request $request)
    {
        $disk = MasterFileService::currentDisk();
        if (!in_array($disk, MasterFileService::isCloudS3Disk(), true)) {
            return $this->sendError(trans('validation.disk_not_support'), [], Response::HTTP_BAD_REQUEST);
        }
        $validator = Validator::make($request->all(), [
            'file_name' => 'required|string|max:255|ends_with:.mp3,.mp4',
        ]);
        if ($validator->fails()) {
            return $this->sendError(trans('validation.file_name_invalid'), $validator->errors(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            // Generate a pre-signed URL
            $name = time() . '-' . $request->file_name;
            $fileName = PublicUploadsPath::Uploads . '/' . date('d-m-Y') . '/' . $name;

            $bucket = config('filesystems.disks.s3.bucket');
            $expiry = FileUpload::EXPIRY_TIME_GET_LINK;  // URL expiration time 24h
            $s3Client = new S3Client([
                'region'  => config('filesystems.disks.s3.region'),
                'version' => 'latest',
                'credentials' => [
                    'key'    => config('filesystems.disks.s3.key'),
                    'secret' => config('filesystems.disks.s3.secret'),
                ],
            ]);
            // Tạo pre-signed URL
            $command = $s3Client->getCommand('PutObject', [
                'Bucket' => $bucket,
                'Key' => $fileName,
                'ACL' => 'public-read', // Tùy chọn: Quyền truy cập của file
            ]);

            $presignedUrl = $s3Client->createPresignedRequest($command, $expiry)->getUri();
            return $this->sendResponse([
                'url' => (string) $presignedUrl,
                'path' => $fileName,
                'file_name' => $name,
            ], trans('file.presigned_url'));
        } catch (Exception $e) {
            $this->logException($e, 'error_exception');
            return $this->apiRespondError($e);
        }
    }
    /*
    * 2 Function upload file chunk & merge file chunk tạm thời chưa sử dụng.
    * Khi nào sử dụng sẽ cập nhật lại
    * Mục đích: Upload file lớn lên server
    */
    public function uploadChunk(Request $request)
    {
        $request->validate([
            'file' => 'required|file',
            'chunkIndex' => 'required|integer',
            'totalChunks' => 'required|integer',
            'fileName' => 'required|string' // Tên file gốc để sử dụng khi tái tạo file
        ]);
        //log dung luong file
        $file = $request->file('file');
        $chunkIndex = $request->input('chunkIndex');
        $totalChunks = $request->input('totalChunks');
        $fileName = $request->input('fileName');

        $chunkFileName = "{$fileName}.part{$chunkIndex}";
        $filePath = $file->storeAs('chunks', $chunkFileName, 'public');

        if ($chunkIndex + 1 == $totalChunks) {
            $finalPath = $this->mergeFileChunks($fileName, $totalChunks);
            return response()->json(['status' => 'success', 'message' => 'File uploaded and merged', 'path' => $finalPath]);
        }

        return response()->json(['status' => 'in_progress', 'message' => 'Chunk uploaded']);
    }

    private function mergeFileChunks($fileName, $totalChunks)
    {
        $finalPath = storage_path("app/public/{$fileName}");
        $fileHandle = fopen($finalPath, 'wb');

        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkFileName = storage_path("app/public/chunks/{$fileName}.part{$i}");
            $chunkFileHandle = fopen($chunkFileName, 'rb');
            while ($content = fread($chunkFileHandle, 1024 * 1024)) {
                fwrite($fileHandle, $content);
            }
            fclose($chunkFileHandle);
            unlink($chunkFileName); // Remove chunk file after merge
        }

        fclose($fileHandle);
        return $finalPath;
    }
}
