<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            // Was true — Laravel auto-registers a storage/{path} route for
            // any disk with 'serve' enabled, and that framework route was
            // silently shadowing our own storage/{path} route in
            // routes/web.php (the one that adds the CORS headers cross-
            // origin PDF/image fetches need), since it gets registered
            // first. Nothing in this app downloads from the private disk
            // via a public URL anyway.
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        // Points straight at public/storage — no storage:link symlink in the
        // loop. The hosting environment's FTP-only deploys can't reliably
        // create or keep a symlink in place (see the stale-directory bug it
        // caused in production), so uploads/downloads read and write this
        // folder directly instead of going through storage/app/public.
        'public' => [
            'driver' => 'local',
            'root' => public_path('storage'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    // No symlink needed — the 'public' disk above points straight at
    // public/storage now.
    'links' => [],

];
