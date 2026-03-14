<?php

namespace App\Http\Controllers\V1;

use App\Exceptions\UploadServiceException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\VideoUploadResource;
use App\Services\V1\AdminUploadService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

class AdminApiController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AdminUploadService $adminService) {}

    public function uploads(): AnonymousResourceCollection|JsonResponse
    {
        try {
            return VideoUploadResource::collection($this->adminService->getPaginatedUploads());
        } catch (UploadServiceException $e) {
            return $this->error($e->getMessage(), 500);
        } catch (Throwable $e) {
            return $this->error('Unable to load uploads list. Please try again.', 500);
        }
    }

    public function uploadDetail(string $uploadId): JsonResponse
    {
        try {
            $upload = $this->adminService->getUploadDetail($uploadId);

            return $this->success(new VideoUploadResource($upload));
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (UploadServiceException $e) {
            return $this->error($e->getMessage(), 500);
        } catch (Throwable $e) {
            return $this->error('Unable to load upload details. Please try again.', 500);
        }
    }

    public function download(string $uploadId): RedirectResponse|JsonResponse
    {
        try {
            $url = $this->adminService->generateDownloadUrl($uploadId);

            return redirect()->away($url);
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (UploadServiceException $e) {
            return $this->error($e->getMessage(), 500);
        } catch (Throwable $e) {
            return $this->error('Unable to generate download link. Please try again.', 500);
        }
    }
}
