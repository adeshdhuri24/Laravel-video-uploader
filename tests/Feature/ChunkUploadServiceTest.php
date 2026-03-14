<?php

use App\Jobs\ProcessVideoUpload;
use App\Models\User;
use App\Models\VideoUpload;
use App\Models\VideoUploadChunk;
use App\Services\V1\ChunkUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');
});

test('initOrResume creates new upload when no upload_id given', function () {
    $service = app(ChunkUploadService::class);
    $user = User::factory()->create();

    $result = $service->initOrResume($user->id, [
        'original_name' => 'new.mp4',
        'file_size' => 1000,
        'total_chunks' => 5,
    ]);

    expect($result['resumed'])->toBeFalse();
    expect($result['upload_id'])->toBeString();
    expect($result['received_chunks'])->toBeArray()->toBeEmpty();

    $this->assertDatabaseHas('video_uploads', [
        'original_name' => 'new.mp4',
        'total_chunks' => 5,
        'received_chunks' => 0,
        'status' => 'pending',
    ]);
});

test('initOrResume returns existing upload when upload_id belongs to user and not completed', function () {
    $service = app(ChunkUploadService::class);
    $user = User::factory()->create();
    $upload = VideoUpload::create([
        'upload_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'original_name' => 'resume.mp4',
        'file_size' => 2000,
        'total_chunks' => 3,
        'received_chunks' => 1,
        'status' => 'uploading',
    ]);
    VideoUploadChunk::create(['upload_id' => $upload->upload_id, 'chunk_index' => 0, 'temp_path' => 'chunks/x/chunk_0']);

    $result = $service->initOrResume($user->id, [
        'original_name' => 'resume.mp4',
        'file_size' => 2000,
        'total_chunks' => 3,
        'upload_id' => $upload->upload_id,
    ]);

    expect($result['resumed'])->toBeTrue();
    expect($result['upload_id'])->toBe($upload->upload_id);
    expect($result['received_chunks'])->toEqual([0]);
});

test('initOrResume creates new upload when upload_id does not match resumable', function () {
    $service = app(ChunkUploadService::class);
    $user = User::factory()->create();
    $upload = VideoUpload::create([
        'upload_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'original_name' => 'old.mp4',
        'file_size' => 100,
        'total_chunks' => 1,
        'received_chunks' => 1,
        'status' => 'processing',
    ]);

    $result = $service->initOrResume($user->id, [
        'original_name' => 'new.mp4',
        'file_size' => 200,
        'total_chunks' => 2,
        'upload_id' => $upload->upload_id,
    ]);

    expect($result['resumed'])->toBeFalse();
    expect($result['upload_id'])->not->toBe($upload->upload_id);
    $this->assertDatabaseCount('video_uploads', 2);
});

test('storeChunk is idempotent for same chunk_index', function () {
    $service = app(ChunkUploadService::class);
    $user = User::factory()->create();
    $upload = VideoUpload::create([
        'upload_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'original_name' => 'idem.mp4',
        'file_size' => 500,
        'total_chunks' => 1,
        'received_chunks' => 1,
        'status' => 'uploading',
    ]);
    VideoUploadChunk::create(['upload_id' => $upload->upload_id, 'chunk_index' => 0, 'temp_path' => 'chunks/a/chunk_0']);
    $file = UploadedFile::fake()->create('chunk', 50, 'application/octet-stream');

    $result = $service->storeChunk($upload, 0, $file);

    expect($result['skipped'])->toBeTrue();
    expect($result['received_chunks'])->toBe(1);
    $this->assertDatabaseCount('video_upload_chunks', 1);
});

test('finalize throws when not all chunks received', function () {
    $service = app(ChunkUploadService::class);
    $user = User::factory()->create();
    $upload = VideoUpload::create([
        'upload_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'original_name' => 'incomplete.mp4',
        'file_size' => 1000,
        'total_chunks' => 3,
        'received_chunks' => 1,
        'status' => 'uploading',
    ]);

    $service->finalize($upload);
})->throws(RuntimeException::class, 'Not all chunks received');

test('finalize dispatches ProcessVideoUpload when complete', function () {
    Queue::fake();
    $service = app(ChunkUploadService::class);
    $user = User::factory()->create();
    $upload = VideoUpload::create([
        'upload_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'original_name' => 'complete.mp4',
        'file_size' => 100,
        'total_chunks' => 1,
        'received_chunks' => 1,
        'status' => 'uploading',
    ]);
    VideoUploadChunk::create(['upload_id' => $upload->upload_id, 'chunk_index' => 0, 'temp_path' => 'chunks/c/chunk_0']);
    Storage::disk('local')->put("chunks/{$upload->upload_id}/chunk_0", 'data');

    $service->finalize($upload);

    Queue::assertPushed(ProcessVideoUpload::class);
});

test('cancel deletes upload and chunk records', function () {
    $service = app(ChunkUploadService::class);
    $user = User::factory()->create();
    $upload = VideoUpload::create([
        'upload_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'original_name' => 'cancel.mp4',
        'file_size' => 100,
        'total_chunks' => 1,
        'received_chunks' => 0,
        'status' => 'pending',
    ]);

    $service->cancel($upload);

    $this->assertDatabaseMissing('video_uploads', ['id' => $upload->id]);
});

test('abandon marks upload cancelled and deletes chunk records', function () {
    $service = app(ChunkUploadService::class);
    $user = User::factory()->create();
    $upload = VideoUpload::create([
        'upload_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'original_name' => 'abandon.mp4',
        'file_size' => 100,
        'total_chunks' => 1,
        'received_chunks' => 1,
        'status' => 'uploading',
    ]);
    VideoUploadChunk::create(['upload_id' => $upload->upload_id, 'chunk_index' => 0, 'temp_path' => 'chunks/ab/chunk_0']);

    $service->abandon($upload->upload_id);

    $upload->refresh();
    expect($upload->status)->toBe('cancelled');
    $this->assertDatabaseCount('video_upload_chunks', 0);
});
