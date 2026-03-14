<?php

namespace App\Http\Requests\Upload;

use Illuminate\Foundation\Http\FormRequest;

class FinalizeUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'upload_id' => ['required', 'string', 'exists:video_uploads,upload_id'],
        ];
    }

    public function messages(): array
    {
        return [
            'upload_id.required' => 'Upload session ID is required.',
            'upload_id.exists' => 'Upload session not found.',
        ];
    }
}
