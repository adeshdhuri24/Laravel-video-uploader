<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoUploadChunk extends Model
{
    protected $fillable = [
        'upload_id',
        'chunk_index',
        'temp_path',
    ];

    protected $casts = [
        'chunk_index' => 'integer',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(VideoUpload::class, 'upload_id', 'upload_id');
    }
}
