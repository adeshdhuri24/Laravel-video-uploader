<?php

namespace App\Http\Controllers\V1;

use App\Exceptions\UploadServiceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Upload\FinalizeUploadRequest;
use App\Http\Requests\Upload\InitUploadRequest;
use App\Http\Requests\Upload\StoreChunkRequest;
use App\Http\Resources\V1\VideoUploadResource;
use App\Models\VideoUpload;
use App\Services\V1\ChunkUploadService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class UploadController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ChunkUploadService $uploadService) {}

    public function init(InitUploadRequest $request): JsonResponse
    {
        try {
            $result = $this->uploadService->initOrResume($request->user()->id, $request->validated());

            return $result['resumed'] ? $this->success($result) : $this->created($result);
        } catch (UploadServiceException $e) {
            return $this->error($e->getMessage(), 500);
        } catch (Throwable $e) {
            return $this->error('Upload session could not be started. Please try again.', 500);
        }
    }

    public function storeChunk(StoreChunkRequest $request): JsonResponse
    {
        $upload = VideoUpload::where('upload_id', $request->upload_id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if (in_array($upload->status, [VideoUpload::STATUS_PROCESSING, VideoUpload::STATUS_COMPLETED])) {
            return $this->error('Upload already finalised.', 422);
        }

        try {
            $result = $this->uploadService->storeChunk($upload, (int) $request->chunk_index, $request->file('chunk'));

            return $this->success($result);
        } catch (UploadServiceException $e) {
            return $this->error($e->getMessage(), 500);
        } catch (Throwable $e) {
            return $this->error('Chunk could not be saved. Please try again.', 500);
        }
    }

    public function finalize(FinalizeUploadRequest $request): JsonResponse
    {
        $upload = VideoUpload::where('upload_id', $request->upload_id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($upload->status === VideoUpload::STATUS_COMPLETED) {
            return $this->success(['status' => $upload->status], 'Already completed.');
        }
        if ($upload->status === VideoUpload::STATUS_PROCESSING) {
            return $this->success(['status' => $upload->status], 'Already processing.');
        }

        try {
            $this->uploadService->finalize($upload);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422, [
                'received_chunks' => $upload->received_chunks,
                'total_chunks' => $upload->total_chunks,
            ]);
        } catch (UploadServiceException $e) {
            return $this->error($e->getMessage(), 500);
        } catch (Throwable $e) {
            return $this->error('Upload could not be finalized. Please try again.', 500);
        }

        return $this->success(['status' => VideoUpload::STATUS_PROCESSING], 'Processing started.');
    }

    public function status(Request $request, string $uploadId): JsonResponse
    {
        $upload = VideoUpload::where('upload_id', $uploadId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return $this->success(new VideoUploadResource($upload));
    }

    public function cancel(Request $request, string $uploadId): JsonResponse
    {
        $upload = VideoUpload::where('upload_id', $uploadId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
        try {
            $this->uploadService->cancel($upload);

            return $this->success(message: 'Upload cancelled.');
        } catch (UploadServiceException $e) {
            return $this->error($e->getMessage(), 500);
        } catch (Throwable $e) {
            return $this->error('Upload could not be cancelled. Please try again.', 500);
        }
    }

    public function abandon(Request $request, string $uploadId): Response|JsonResponse
    {
        VideoUpload::where('upload_id', $uploadId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
        try {
            $this->uploadService->abandon($uploadId);

            return $this->noContent();
        } catch (UploadServiceException $e) {
            return $this->error($e->getMessage(), 500);
        } catch (Throwable $e) {
            return $this->error('Upload could not be abandoned.', 500);
        }
    }
}
