<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoUpload extends Model
{
    const STATUS_PENDING = 'pending';

    const STATUS_UPLOADING = 'uploading';

    const STATUS_PROCESSING = 'processing';

    const STATUS_COMPLETED = 'completed';

    const STATUS_FAILED = 'failed';

    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'upload_id',
        'user_id',
        'original_name',
        'file_size',
        'total_chunks',
        'received_chunks',
        'status',
        's3_path',
        'error_message',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'total_chunks' => 'integer',
        'received_chunks' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(VideoUploadChunk::class, 'upload_id', 'upload_id');
    }

    public function getProgressPercentAttribute(): int
    {
        if ($this->total_chunks === 0) {
            return 0;
        }

        return (int) round(($this->received_chunks / $this->total_chunks) * 100);
    }

    public function isComplete(): bool
    {
        return $this->received_chunks >= $this->total_chunks;
    }

    public function getFileSizeHumanAttribute(): string
    {
        return $this->file_size ? number_format($this->file_size / 1024 / 1024, 2).' MB' : '0 MB';
    }
}
