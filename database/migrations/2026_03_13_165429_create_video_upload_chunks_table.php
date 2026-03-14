<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_upload_chunks', function (Blueprint $table) {
            $table->id();
            $table->string('upload_id');
            $table->unsignedInteger('chunk_index');
            $table->string('temp_path');
            $table->timestamps();

            $table->foreign('upload_id')
                ->references('upload_id')
                ->on('video_uploads')
                ->cascadeOnDelete();

            $table->unique(['upload_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_upload_chunks');
    }
};
