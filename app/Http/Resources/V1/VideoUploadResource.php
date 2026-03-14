<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoUploadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'upload_id' => $this->upload_id,
            'original_name' => $this->original_name,
            'file_size' => $this->file_size,
            'file_size_human' => $this->file_size_human,
            'total_chunks' => $this->total_chunks,
            'received_chunks' => $this->received_chunks,
            'progress_percent' => $this->progress_percent,
            'status' => $this->status,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at->format('M d, Y H:i:s'),
            'updated_at' => $this->updated_at->format('M d, Y H:i:s'),
        ];
    }
}
