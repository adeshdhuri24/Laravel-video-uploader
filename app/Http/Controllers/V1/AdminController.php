<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function index(): View
    {
        return view('admin.dashboard');
    }

    public function upload(): View
    {
        $uploadConfig = array_merge(config('upload.frontend'), [
            'chunk_size_bytes' => config('upload.chunk_size'),
            'max_file_size_bytes' => config('upload.max_file_size'),
        ]);

        return view('admin.upload', ['uploadConfig' => $uploadConfig]);
    }

    public function show(string $uploadId): View
    {
        return view('admin.show', ['uploadId' => $uploadId]);
    }
}
