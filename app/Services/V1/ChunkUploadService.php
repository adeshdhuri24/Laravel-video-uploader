<?php

namespace App\Services\V1;

use App\Exceptions\UploadServiceException;
use App\Jobs\ProcessVideoUpload;
use App\Models\VideoUpload;
use App\Models\VideoUploadChunk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ChunkUploadService
{
    public function initOrResume(int $userId, array $data): array
    {
        try {
            if (! empty($data['upload_id'])) {
                $existing = VideoUpload::where('upload_id', $data['upload_id'])
                    ->where('user_id', $userId)
                    ->whereNotIn('status', [
                        VideoUpload::STATUS_COMPLETED,
                        VideoUpload::STATUS_PROCESSING,
                    ])
                    ->first();

                if ($existing) {
                    return [
                        'upload_id' => $existing->upload_id,
                        'received_chunks' => $existing->chunks()->pluck('chunk_index')->toArray(),
                        'resumed' => true,
                    ];
                }
            }

            $upload = VideoUpload::create([
                'upload_id' => (string) Str::uuid(),
                'user_id' => $userId,
                'original_name' => $data['original_name'],
                'file_size' => $data['file_size'],
                'total_chunks' => $data['total_chunks'],
                'received_chunks' => 0,
                'status' => VideoUpload::STATUS_PENDING,
            ]);

            return [
                'upload_id' => $upload->upload_id,
                'received_chunks' => [],
                'resumed' => false,
            ];
        } catch (Throwable $e) {
            Log::error('[ChunkUploadService] initOrResume', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new UploadServiceException('Upload session could not be started. Please try again.', 0, $e);
        }
    }

    public function storeChunk(VideoUpload $upload, int $chunkIndex, UploadedFile $file): array
    {
        $alreadyStored = VideoUploadChunk::where('upload_id', $upload->upload_id)
            ->where('chunk_index', $chunkIndex)
            ->exists();

        if ($alreadyStored) {
            return [
                'received_chunks' => $upload->received_chunks,
                'total_chunks' => $upload->total_chunks,
                'skipped' => true,
            ];
        }

        try {
            $basePath = config('upload.local_chunks_path');
            $dir = "{$basePath}/{$upload->upload_id}";
            $path = $file->storeAs($dir, "chunk_{$chunkIndex}");

            DB::transaction(function () use ($upload, $chunkIndex, $path) {
                VideoUploadChunk::create([
                    'upload_id' => $upload->upload_id,
                    'chunk_index' => $chunkIndex,
                    'temp_path' => $path,
                ]);
                $upload->increment('received_chunks');
                $upload->status = VideoUpload::STATUS_UPLOADING;
                $upload->save();
            });

            $upload->refresh();

            return [
                'received_chunks' => $upload->received_chunks,
                'total_chunks' => $upload->total_chunks,
                'skipped' => false,
            ];
        } catch (Throwable $e) {
            Log::error('[ChunkUploadService] storeChunk', [
                'upload_id' => $upload->upload_id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new UploadServiceException('Chunk could not be saved. Please try again.', 0, $e);
        }
    }

    public function finalize(VideoUpload $upload): void
    {
        if (! $upload->isComplete()) {
            throw new \RuntimeException(
                "Not all chunks received ({$upload->received_chunks}/{$upload->total_chunks})."
            );
        }

        try {
            $upload->update(['status' => VideoUpload::STATUS_PROCESSING]);
            ProcessVideoUpload::dispatch($upload);
        } catch (Throwable $e) {
            Log::error('[ChunkUploadService] finalize', [
                'upload_id' => $upload->upload_id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new UploadServiceException('Upload could not be finalized. Please try again.', 0, $e);
        }
    }

    public function cancel(VideoUpload $upload): void
    {
        try {
            $basePath = config('upload.local_chunks_path');
            $chunkDir = "{$basePath}/{$upload->upload_id}";

            if (Storage::exists($chunkDir)) {
                Storage::deleteDirectory($chunkDir);
            }

            $upload->delete();
        } catch (Throwable $e) {
            Log::error('[ChunkUploadService] cancel', [
                'upload_id' => $upload->upload_id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new UploadServiceException('Upload could not be cancelled. Please try again.', 0, $e);
        }
    }

    public function abandon(string $uploadId): void
    {
        $upload = VideoUpload::where('upload_id', $uploadId)
            ->whereIn('status', [
                VideoUpload::STATUS_PENDING,
                VideoUpload::STATUS_UPLOADING,
            ])
            ->first();

        if (! $upload) {
            return;
        }

        try {
            foreach ($upload->chunks as $chunk) {
                if (Storage::disk('local')->exists($chunk->temp_path)) {
                    Storage::disk('local')->delete($chunk->temp_path);
                }
            }

            DB::transaction(function () use ($upload) {
                $upload->chunks()->delete();
                $upload->update([
                    'status' => VideoUpload::STATUS_CANCELLED,
                    'error_message' => 'Upload abandoned — user left the page.',
                ]);
            });
        } catch (Throwable $e) {
            Log::error('[ChunkUploadService] abandon', [
                'upload_id' => $uploadId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new UploadServiceException('Upload could not be abandoned.', 0, $e);
        }
    }
}
