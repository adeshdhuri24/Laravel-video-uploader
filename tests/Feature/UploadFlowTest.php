<?php

use App\Jobs\ProcessVideoUpload;
use App\Jobs\UploadVideoToS3;
use App\Mail\VideoUploadedMail;
use App\Models\User;
use App\Models\VideoUpload;
use App\Models\VideoUploadChunk;
use App\Services\V1\VideoAssemblyService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('s3');
});

test('init creates a new upload and returns upload_id', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/upload/init', [
        'original_name' => 'test-video.mp4',
        'file_size' => 100 * 1024 * 1024,
        'total_chunks' => 10,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.resumed', false);
    expect($response->json('data.upload_id'))->toBeString();
    expect($response->json('data.received_chunks'))->toBeArray()->toBeEmpty();

    $this->assertDatabaseHas('video_uploads', [
        'original_name' => 'test-video.mp4',
        'file_size' => 100 * 1024 * 1024,
        'total_chunks' => 10,
        'received_chunks' => 0,
        'status' => 'pending',
    ]);
});

test('init resume returns existing upload and received_chunks', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $upload = VideoUpload::create([
        'upload_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'original_name' => 'resume-video.mp4',
        'file_size' => 50 * 1024 * 1024,
        'total_chunks' => 5,
        'received_chunks' => 2,
        'status' => 'uploading',
    ]);
    VideoUploadChunk::create(['upload_id' => $upload->upload_id, 'chunk_index' => 0, 'temp_path' => 'chunks/x/chunk_0']);
    VideoUploadChunk::create(['upload_id' => $upload->upload_id, 'chunk_index' => 1, 'temp_path' => 'chunks/x/chunk_1']);

    $response = $this->postJson('/api/v1/upload/init', [
        'original_name' => 'resume-video.mp4',
        'file_size' => 50 * 1024 * 1024,
        'total_chunks' => 5,
        'upload_id' => $upload->upload_id,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.resumed', true)
        ->assertJsonPath('data.upload_id', $upload->upload_id);
    expect($response->json('data.received_chunks'))->toEqual([0, 1]);
});

test('init validates file_size and total_chunks', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/upload/init', [
        'original_name' => 'x.mp4',
        'file_size' => 999 * 1024 * 1024, // over default max
        'total_chunks' => 1,
    ]);

    $response->assertStatus(422);
});

test('store chunk increments received_chunks and returns progress', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $upload = VideoUpload::create([
        'upload_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'original_name' => 'chunk-video.mp4',
        'file_size' => 20 * 1024 * 1024,
        'total_chunks' => 2,
        'received_chunks' => 0,
        'status' => 'pending',
    ]);
    $file = UploadedFile::fake()->create('chunk', 1024, 'application/octet-stream');

    $response = $this->postJson('/api/v1/upload/chunk', [
        'upload_id' => $upload->upload_id,
        'chunk_index' => 0,
        'chunk' => $file,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.received_chunks', 1)
        ->assertJsonPath('data.total_chunks', 2)
        ->assertJsonPath('data.skipped', false);

    $upload->refresh();
    expect($upload->received_chunks)->toBe(1);
    expect($upload->status)->toBe('uploading');
});

test('store chunk is idempotent for same chunk_index', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $upload = VideoUpload::create([
        'upload_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'original_name' => 'idem.mp4',
        'file_size' => 1024,
        'total_chunks' => 1,
        'received_chunks' => 1,
        'status' => 'uploading',
    ]);
    VideoUploadChunk::create(['upload_id' => $upload->upload_id, 'chunk_index' => 0, 'temp_path' => 'chunks/a/chunk_0']);
    $file = UploadedFile::fake()->create('chunk', 100, 'application/octet-stream');

    $response = $this->postJson('/api/v1/upload/chunk', [
        'upload_id' => $upload->upload_id,
        'chunk_index' => 0,
        'chunk' => $file,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.skipped', true)
        ->assertJsonPath('data.received_chunks', 1);
});

test('store chunk returns 422 when upload_id belongs to another user', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $other = User::factory()->create();
    $upload = VideoUpload::create([
        'upload_id' => (string) Str::uuid(),
        'user_id' => $other->id,
        'original_name' => 'other.mp4',
        'file_size' => 1024,
        'total_chunks' => 1,
        'received_chunks' => 0,
        'status' => 'pending',
    ]);
    $file = UploadedFile::fake()->create('chunk', 100, 'application/octet-stream');

    $response = $this->postJson('/api/v1/upload/chunk', [
        'upload_id' => $upload->upload_id,
        'chunk_index' => 0,
        'chunk' => $file,
    ]);

    $response->assertStatus(422);
});

test('store chunk returns 422 when chunk_index is out of range', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $upload = VideoUpload::create([
        'upload_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'original_name' => 'range.mp4',
        'file_size' => 2048,
        'total_chunks' => 2,
        'received_chunks' => 0,
        'status' => 'pending',
    ]);
    $file = UploadedFile::fake()->create('chunk', 100, 'application/octet-stream');

    $response = $this->postJson('/api/v1/upload/chunk', [
        'upload_id' => $upload->upload_id,
        'chunk_index' => 2,
        'chunk' => $file,
    ]);

    $response->assertStatus(422);
});

test('finalize dispatches ProcessVideoUpload when all chunks received', function () {
    Queue::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $upload = VideoUpload::create([
        'upload_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'original_name' => 'final.mp4',
        'file_size' => 1024,
        'total_chunks' => 1,
        'received_chunks' => 1,
        'status' => 'uploading',
    ]);
    VideoUploadChunk::create(['upload_id' => $upload->upload_id, 'chunk_index' => 0, 'temp_path' => 'chunks/f/chunk_0']);
    $basePath = config('upload.local_chunks_path');
    Storage::disk('local')->put("{$basePath}/{$upload->upload_id}/chunk_0", 'chunk content');

    $response = $this->postJson('/api/v1/upload/finalize', [
        'upload_id' => $upload->upload_id,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'processing');
    Queue::assertPushed(ProcessVideoUpload::class);
});

test('finalize returns 422 when not all chunks received', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $upload = VideoUpload::create([
        'upload_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'original_name' => 'incomplete.mp4',
        'file_size' => 2048,
        'total_chunks' => 2,
        'received_chunks' => 1,
        'status' => 'uploading',
    ]);

    $response = $this->postJson('/api/v1/upload/finalize', [
        'upload_id' => $upload->upload_id,
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('errors.received_chunks', 1)
        ->assertJsonPath('errors.total_chunks', 2);
});

test('status returns upload with progress_percent', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $upload = VideoUpload::create([
        'upload_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'original_name' => 'status.mp4',
        'file_size' => 100,
        'total_chunks' => 4,
        'received_chunks' => 2,
        'status' => 'uploading',
    ]);

    $response = $this->getJson("/api/v1/upload/{$upload->upload_id}/status");

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'uploading')
        ->assertJsonPath('data.progress_percent', 50);
});

test('cancel deletes upload and returns success', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $upload = VideoUpload::create([
        'upload_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'original_name' => 'cancel.mp4',
        'file_size' => 100,
        'total_chunks' => 1,
        'received_chunks' => 0,
        'status' => 'pending',
    ]);

    $response = $this->deleteJson("/api/v1/upload/{$upload->upload_id}");

    $response->assertStatus(200);
    $this->assertDatabaseMissing('video_uploads', ['id' => $upload->id]);
});

test('abandon returns 204 and scopes to current user', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $upload = VideoUpload::create([
        'upload_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'original_name' => 'abandon.mp4',
        'file_size' => 100,
        'total_chunks' => 2,
        'received_chunks' => 1,
        'status' => 'uploading',
    ]);
    VideoUploadChunk::create(['upload_id' => $upload->upload_id, 'chunk_index' => 0, 'temp_path' => 'chunks/a/chunk_0']);

    $response = $this->postJson("/api/v1/upload/{$upload->upload_id}/abandon");

    $response->assertStatus(204);
    $upload->refresh();
    expect($upload->status)->toBe('cancelled');
});

test('abandon returns 404 for another users upload', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $other = User::factory()->create();
    $upload = VideoUpload::create([
        'upload_id' => (string) Str::uuid(),
        'user_id' => $other->id,
        'original_name' => 'other.mp4',
        'file_size' => 100,
        'total_chunks' => 1,
        'received_chunks' => 0,
        'status' => 'pending',
    ]);

    $response = $this->postJson("/api/v1/upload/{$upload->upload_id}/abandon");

    $response->assertStatus(404);
});

test('ProcessVideoUpload job dispatches UploadVideoToS3 after assembly', function () {
    Queue::fake();
    $user = User::factory()->create();
    $upload = VideoUpload::create([
        'upload_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'original_name' => 'dispatch-test.mp4',
        'file_size' => 10,
        'total_chunks' => 1,
        'received_chunks' => 1,
        'status' => 'processing',
    ]);
    $basePath = config('upload.local_chunks_path');
    $chunkDir = "{$basePath}/{$upload->upload_id}";
    Storage::disk('local')->put("{$chunkDir}/chunk_0", 'content');
    VideoUploadChunk::create(['upload_id' => $upload->upload_id, 'chunk_index' => 0, 'temp_path' => "{$chunkDir}/chunk_0"]);

    $job = new ProcessVideoUpload($upload);
    $job->handle(app(VideoAssemblyService::class));

    Queue::assertPushed(UploadVideoToS3::class, function ($j) use ($upload) {
        return $j->upload->id === $upload->id && $j->assembledPath !== '';
    });
});

test('ProcessVideoUpload job assembles uploads to S3 and queues admin email', function () {
    Mail::fake();
    $user = User::factory()->create();
    $upload = VideoUpload::create([
        'upload_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'original_name' => 'job-video.mp4',
        'file_size' => 12,
        'total_chunks' => 1,
        'received_chunks' => 1,
        'status' => 'processing',
    ]);
    $basePath = config('upload.local_chunks_path');
    $chunkDir = "{$basePath}/{$upload->upload_id}";
    Storage::disk('local')->put("{$chunkDir}/chunk_0", 'video chunk content');
    VideoUploadChunk::create(['upload_id' => $upload->upload_id, 'chunk_index' => 0, 'temp_path' => "{$chunkDir}/chunk_0"]);

    $job = new ProcessVideoUpload($upload);
    $job->handle(app(VideoAssemblyService::class));

    $upload->refresh();
    expect($upload->status)->toBe('completed');
    expect($upload->s3_path)->not->toBeNull();
    Mail::assertQueued(VideoUploadedMail::class, function ($mailable) use ($upload) {
        return $mailable->upload->id === $upload->id;
    });
});

test('ProcessVideoUpload job failed method sets upload to failed', function () {
    $user = User::factory()->create();
    $upload = VideoUpload::create([
        'upload_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'original_name' => 'fail.mp4',
        'file_size' => 100,
        'total_chunks' => 1,
        'received_chunks' => 1,
        'status' => 'processing',
    ]);

    $job = new ProcessVideoUpload($upload);
    $job->failed(new RuntimeException('S3 error'));

    $upload->refresh();
    expect($upload->status)->toBe('failed');
    expect($upload->error_message)->toBe('S3 error');
});
