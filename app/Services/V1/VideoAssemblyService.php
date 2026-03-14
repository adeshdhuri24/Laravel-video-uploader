<?php

namespace App\Services\V1;

use App\Exceptions\UploadServiceException;
use App\Models\VideoUpload;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class VideoAssemblyService
{
    public function assemble(VideoUpload $upload): string
    {
        try {
            $uploadId = $upload->upload_id;
            $basePath = config('upload.local_chunks_path');
            $chunkDir = "{$basePath}/{$uploadId}";
            $assembledPath = "{$chunkDir}/assembled_{$uploadId}";

            Log::info('[VideoAssemblyService] Starting assembly', [
                'upload_id' => $uploadId,
                'original_name' => $upload->original_name,
                'total_chunks' => $upload->total_chunks,
                'file_size' => $upload->file_size,
            ]);

            Storage::makeDirectory($chunkDir);

            $outAbsPath = Storage::path($assembledPath);
            $outHandle = fopen($outAbsPath, 'wb');

            if ($outHandle === false) {
                throw new \RuntimeException("Cannot open output file for assembly: {$outAbsPath}");
            }

            try {
                for ($i = 0; $i < $upload->total_chunks; $i++) {
                    $chunkAbsPath = Storage::path("{$chunkDir}/chunk_{$i}");

                    if (! file_exists($chunkAbsPath)) {
                        throw new \RuntimeException("Missing chunk {$i} for upload {$uploadId}");
                    }

                    $inHandle = fopen($chunkAbsPath, 'rb');

                    if ($inHandle === false) {
                        throw new \RuntimeException("Cannot open chunk {$i} for reading");
                    }

                    stream_copy_to_stream($inHandle, $outHandle);
                    fclose($inHandle);
                }
            } finally {
                fclose($outHandle);
            }

            $assembledSize = filesize($outAbsPath);

            Log::info('[VideoAssemblyService] Assembly complete', [
                'upload_id' => $uploadId,
                'assembled_path' => $outAbsPath,
                'assembled_size_mb' => round($assembledSize / 1048576, 2).' MB',
            ]);

            return $outAbsPath;
        } catch (Throwable $e) {
            Log::error('[VideoAssemblyService] assemble', [
                'upload_id' => $upload->upload_id ?? null,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new UploadServiceException('Video assembly failed. Please try again or re-upload.', 0, $e);
        }
    }

    public function uploadToS3(VideoUpload $upload, string $assembledAbsPath): string
    {
        $uploadId = $upload->upload_id;
        $prefix = config('upload.s3_prefix');
        $s3Path = sprintf(
            '%s/%s/%s/%s/%s',
            $prefix,
            now()->format('Y'),
            now()->format('m'),
            $uploadId,
            $upload->original_name
        );

        Log::info('[VideoAssemblyService] Uploading to S3', [
            'upload_id' => $uploadId,
            's3_bucket' => config('filesystems.disks.s3.bucket'),
            's3_region' => config('filesystems.disks.s3.region'),
            's3_path' => $s3Path,
        ]);

        $stream = fopen($assembledAbsPath, 'rb');
        if ($stream === false) {
            throw new UploadServiceException('Could not read assembled file.', 0);
        }

        try {
            Storage::disk('s3')->put($s3Path, $stream);
        } catch (Throwable $e) {
            Log::error('[VideoAssemblyService] uploadToS3', [
                'upload_id' => $uploadId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new UploadServiceException('Upload to storage failed. Please try again.', 0, $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        Log::info('[VideoAssemblyService] S3 upload succeeded', [
            'upload_id' => $uploadId,
            's3_path' => $s3Path,
        ]);

        return $s3Path;
    }

    public function cleanup(string $uploadId): void
    {
        try {
            $basePath = config('upload.local_chunks_path');
            $chunkDir = "{$basePath}/{$uploadId}";

            Storage::deleteDirectory($chunkDir);

            Log::info('[VideoAssemblyService] Local temp files deleted', [
                'upload_id' => $uploadId,
            ]);
        } catch (Throwable $e) {
            Log::error('[VideoAssemblyService] cleanup', [
                'upload_id' => $uploadId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new UploadServiceException('Cleanup of temporary files failed.', 0, $e);
        }
    }
}
