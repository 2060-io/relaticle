<?php

declare(strict_types=1);

return [
    'max_rows' => 10_000,
    'max_file_size' => 10 * 1024 * 1024, // 10MB
    'storage_path' => storage_path('app/imports'),
    'chunk_size' => 500,
    'execution_time_box' => 240,
    'store' => [
        'disk' => env('IMPORT_STORE_DISK'),
        'read_cache_path' => storage_path('framework/cache/imports'),
        'lock' => [
            'ttl' => 150,
            'execution_ttl' => 360,
            'wait' => ['web' => 10, 'job' => 60],
        ],
    ],
];
