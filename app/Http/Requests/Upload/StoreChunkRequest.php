<?php

namespace App\Http\Requests\Upload;

use App\Models\VideoUpload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChunkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxChunkKb = (int) (config('upload.chunk_size') / 1024);

        return [
            'upload_id' => [
                'required',
                'string',
                Rule::exists('video_uploads', 'upload_id')->where('user_id', $this->user()->id),
            ],
            'chunk_index' => [
                'required',
                'integer',
                'min:0',
                $this->chunkIndexInRange(),
            ],
            'chunk' => ['required', 'file', 'max:'.$maxChunkKb],
        ];
    }

    protected function chunkIndexInRange(): \Closure
    {
        return function (string $attribute, int $value, \Closure $fail) {
            $upload = VideoUpload::where('upload_id', $this->input('upload_id'))
                ->where('user_id', $this->user()->id)
                ->first();
            if ($upload && $value >= $upload->total_chunks) {
                $fail('Chunk index is out of range for this upload.');
            }
        };
    }

    public function messages(): array
    {
        $maxMb = (int) round(config('upload.chunk_size') / 1048576);

        return [
            'upload_id.required' => 'Upload session ID is required.',
            'upload_id.exists' => 'Upload session not found or does not belong to you.',
            'chunk_index.required' => 'Chunk index is required.',
            'chunk_index.integer' => 'Chunk index must be a number.',
            'chunk_index.min' => 'Chunk index must be 0 or greater.',
            'chunk.required' => 'No chunk data received.',
            'chunk.file' => 'The chunk must be a valid file.',
            'chunk.max' => "Chunk size exceeds the {$maxMb} MB limit.",
        ];
    }
}
