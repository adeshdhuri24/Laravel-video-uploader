<?php

namespace App\Http\Requests\Upload;

use Illuminate\Foundation\Http\FormRequest;

class InitUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $allowed = config('upload.allowed_extensions', ['mp4', 'webm', 'mov', 'avi', 'mkv']);

        return [
            'original_name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($allowed): void {
                    $ext = strtolower(pathinfo($value, PATHINFO_EXTENSION));
                    if ($ext === '' || ! in_array($ext, $allowed, true)) {
                        $fail('Only video files are allowed ('.implode(', ', $allowed).').');
                    }
                },
            ],
            'file_size' => ['required', 'integer', 'min:1', 'max:'.config('upload.max_file_size')],
            'total_chunks' => ['required', 'integer', 'min:1', 'max:'.config('upload.max_total_chunks')],
            'upload_id' => ['sometimes', 'nullable', 'string', 'uuid'],
        ];
    }

    public function messages(): array
    {
        $maxMb = (int) round(config('upload.max_file_size') / 1048576);

        return [
            'original_name.required' => 'A filename is required.',
            'original_name.max' => 'Filename must not exceed 255 characters.',
            'file_size.required' => 'File size is required.',
            'file_size.min' => 'File size must be at least 1 byte.',
            'file_size.max' => "File size must not exceed {$maxMb} MB.",
            'total_chunks.required' => 'Total chunk count is required.',
            'total_chunks.min' => 'There must be at least 1 chunk.',
            'total_chunks.max' => 'Too many chunks. Maximum is '.config('upload.max_total_chunks').'.',
            'upload_id.uuid' => 'Invalid resume upload ID format.',
        ];
    }
}
