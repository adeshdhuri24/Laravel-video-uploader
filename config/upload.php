<?php

return [

    'chunk_size' => (int) (env('UPLOAD_CHUNK_SIZE_MB', 10) ?: 10) * 1024 * 1024,

    'max_file_size' => (int) (env('UPLOAD_MAX_FILE_SIZE_MB', 300) ?: 300) * 1024 * 1024,

    'max_total_chunks' => (int) (env('UPLOAD_MAX_TOTAL_CHUNKS', 10000) ?: 10000),

    'allowed_extensions' => array_map(
        'strtolower',
        array_filter(explode(',', env('UPLOAD_ALLOWED_EXTENSIONS', 'mp4,webm,mov,avi,mkv')))
    ),

    's3_prefix' => trim(env('UPLOAD_S3_PREFIX', 'videos') ?: 'videos', '/'),

    'local_chunks_path' => trim(env('UPLOAD_LOCAL_CHUNKS_PATH', 'chunks') ?: 'chunks', '/'),

    'download_url_expiry_minutes' => (int) (env('UPLOAD_DOWNLOAD_URL_EXPIRY_MINUTES', 5) ?: 5),

    'frontend' => [
        'poll_interval_ms' => (int) (env('UPLOAD_POLL_INTERVAL_MS', 2000) ?: 2000),
        'max_retries' => (int) (env('UPLOAD_CHUNK_MAX_RETRIES', 3) ?: 3),
    ],

];
