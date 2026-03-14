<?php

namespace App\Services\V1;

use App\Exceptions\UploadServiceException;
use App\Models\VideoUpload;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AdminUploadService
{
    public function getPaginatedUploads(int $perPage = 15): LengthAwarePaginator
    {
        try {
            return VideoUpload::with('user')
                ->latest()
                ->paginate($perPage);
        } catch (Throwable $e) {
            Log::error('[AdminUploadService] getPaginatedUploads', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new UploadServiceException('Unable to load uploads list. Please try again.', 0, $e);
        }
    }

    public function getUploadDetail(string $uploadId): VideoUpload
    {
        try {
            return VideoUpload::where('upload_id', $uploadId)
                ->with(['chunks' => fn ($q) => $q->orderBy('chunk_index')])
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('[AdminUploadService] getUploadDetail', [
                'upload_id' => $uploadId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new UploadServiceException('Unable to load upload details. Please try again.', 0, $e);
        }
    }

    public function generateDownloadUrl(string $uploadId): string
    {
        try {
            $upload = VideoUpload::where('upload_id', $uploadId)
                ->where('status', VideoUpload::STATUS_COMPLETED)
                ->firstOrFail();

            $expiry = config('upload.download_url_expiry_minutes', 5);

            return Storage::disk('s3')->temporaryUrl(
                $upload->s3_path,
                now()->addMinutes($expiry),
                ['ResponseContentDisposition' => 'attachment; filename="'.$upload->original_name.'"']
            );
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('[AdminUploadService] generateDownloadUrl', [
                'upload_id' => $uploadId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new UploadServiceException('Unable to generate download link. Please try again.', 0, $e);
        }
    }
}
