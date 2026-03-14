<?php

namespace App\Jobs;

use App\Mail\VideoUploadedMail;
use App\Models\VideoUpload;
use App\Services\V1\VideoAssemblyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class UploadVideoToS3 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 15, 30];

    public int $timeout = 600;

    public function __construct(
        public VideoUpload $upload,
        public string $assembledPath
    ) {
        $this->onQueue('uploads');
    }

    public function handle(VideoAssemblyService $assemblyService): void
    {
        $upload = $this->upload->fresh();

        if (! $upload || $upload->status === VideoUpload::STATUS_COMPLETED) {
            Log::info('[UploadVideoToS3] Skipped — already completed or not found', [
                'upload_id' => $this->upload->upload_id,
            ]);

            return;
        }

        $s3Path = $assemblyService->uploadToS3($upload, $this->assembledPath);
        $assemblyService->cleanup($upload->upload_id);

        $upload->update([
            'status' => VideoUpload::STATUS_COMPLETED,
            's3_path' => $s3Path,
        ]);

        Log::info('[UploadVideoToS3] Completed', [
            'upload_id' => $upload->upload_id,
            's3_path' => $s3Path,
        ]);

        Mail::to(config('app.admin_email'))
            ->queue((new VideoUploadedMail($upload))->onQueue('notifications'));
    }

    public function failed(Throwable $exception): void
    {
        Log::error('[UploadVideoToS3] Job failed', [
            'upload_id' => $this->upload->upload_id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        $this->upload->update([
            'status' => VideoUpload::STATUS_FAILED,
            'error_message' => $exception->getMessage(),
        ]);

        try {
            app(VideoAssemblyService::class)->cleanup($this->upload->upload_id);
        } catch (Throwable $e) {
            Log::warning('[UploadVideoToS3] Cleanup after failure', [
                'upload_id' => $this->upload->upload_id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
