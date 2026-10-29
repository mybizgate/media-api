<?php

/*
|--------------------------------------------------------------------------
| MyBizGate Media API
|--------------------------------------------------------------------------
|
| All values are driven by .env so that no environment-specific path or
| limit lives in PHP code. Sizes are expressed in kilobytes to match the
| Laravel "max" validation rule.
|
*/

return [

    'service' => 'MyBizGate Media API',

    'version' => '1.0.0',

    // Laravel filesystem disk holding every tenant's media (see filesystems.php).
    'disk' => env('MEDIA_DISK', 'media'),

    // Top-level directory inside the disk; each tenant gets its own sub-tree.
    'tenants_directory' => 'tenants',

    'tenant' => [
        'header' => 'X-Client-Code',
        'pattern' => '/^[a-z0-9-]{2,50}$/',
    ],

    'module' => [
        'pattern' => '/^[a-z0-9_-]{1,50}$/',
        'default' => 'general',
    ],

    'entity_id' => [
        'pattern' => '/^[A-Za-z0-9._-]{1,100}$/',
    ],

    'visibility' => [
        'default' => 'public',
        'allowed' => ['public', 'private'],
    ],

    'image' => [
        'max_size_kb' => (int) env('MEDIA_IMAGE_MAX_SIZE_KB', 10240),
        'max_width' => (int) env('MEDIA_IMAGE_MAX_WIDTH', 1600),
        'max_height' => (int) env('MEDIA_IMAGE_MAX_HEIGHT', 1600),
        'webp_quality' => (int) env('MEDIA_IMAGE_WEBP_QUALITY', 80),
        'filename' => 'image.webp',

        // Server-detected MIME type => accepted file extensions.
        'allowed' => [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/webp' => ['webp'],
        ],

        // AVIF input is only enabled when the runtime GD build can decode it.
        'allow_avif' => (bool) env('MEDIA_IMAGE_ALLOW_AVIF', true),
    ],

    'thumbnail' => [
        'max_width' => (int) env('MEDIA_THUMB_MAX_WIDTH', 400),
        'max_height' => (int) env('MEDIA_THUMB_MAX_HEIGHT', 400),
        'webp_quality' => (int) env('MEDIA_THUMB_WEBP_QUALITY', 75),
        'filename' => 'thumb.webp',
    ],

    'file' => [
        'max_size_kb' => (int) env('MEDIA_FILE_MAX_SIZE_KB', 25600),
        'basename' => 'file',

        // Server-detected MIME type => accepted file extensions.
        'allowed' => [
            'application/pdf' => ['pdf'],
            'text/plain' => ['txt', 'csv'],
            'text/csv' => ['csv'],
            'application/csv' => ['csv'],
            'application/msword' => ['doc'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            'application/vnd.ms-excel' => ['xls'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        ],

        // Document types that may be previewed inline; everything else downloads.
        'inline' => ['application/pdf'],
    ],

    // Extensions that are rejected regardless of detected MIME type.
    'blocked_extensions' => [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'html', 'htm', 'xhtml', 'js', 'mjs', 'sh', 'bash', 'zsh',
        'exe', 'bin', 'jar', 'war', 'bat', 'cmd', 'com', 'msi', 'dll', 'so',
        'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz',
        'svg', 'xml', 'cgi', 'pl', 'py', 'rb', 'ps1', 'vbs',
    ],

    'metadata_filename' => 'metadata.json',

    'cache' => [
        'public' => 'public, max-age=86400',
        'private' => 'private, no-store',
    ],

    'listing' => [
        'per_page' => 50,
        'max_per_page' => 200,
    ],

];
