<?php

namespace App\Jobs;

use App\Models\VideoUpload;
use App\Services\V1\VideoAssemblyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessVideoUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public int $timeout = 600;

    public function __construct(public VideoUpload $upload)
    {
        $this->onQueue('uploads');
    }

    public function handle(VideoAssemblyService $assemblyService): void
    {
        $upload = $this->upload->fresh();

        if (! $upload || $upload->status === VideoUpload::STATUS_COMPLETED) {
            Log::info('[ProcessVideoUpload] Skipped — already completed or not found', [
                'upload_id' => $this->upload->upload_id,
            ]);

            return;
        }

        $assembledPath = $assemblyService->assemble($upload);

        Log::info('[ProcessVideoUpload] Assembly done, dispatching S3 upload', [
            'upload_id' => $upload->upload_id,
        ]);

        UploadVideoToS3::dispatch($upload, $assembledPath);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('[ProcessVideoUpload] Job failed', [
            'upload_id' => $this->upload->upload_id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        $this->upload->update([
            'status' => VideoUpload::STATUS_FAILED,
            'error_message' => $exception->getMessage(),
        ]);
    }
}
