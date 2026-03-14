<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_uploads', function (Blueprint $table) {
            $table->id();
            $table->string('upload_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->unsignedInteger('total_chunks');
            $table->unsignedInteger('received_chunks')->default(0);
            $table->enum('status', ['pending', 'uploading', 'processing', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->string('s3_path')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_uploads');
    }
};
