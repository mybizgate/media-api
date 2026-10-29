<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
        |--------------------------------------------------------------------------
        | MyBizGate Tenant Media
        |--------------------------------------------------------------------------
        |
        | Runtime uploads for every MyBizGate tenant. The root lives outside the
        | application and outside the public web root; files are only ever served
        | through the API. Backing up or migrating this directory is enough to
        | preserve all media, because metadata.json sits beside each file.
        |
        | Local:      /home/jul/Workspace/mybizgate/uploads/media-api
        | Production: /home/mybizgat/data/media-api/uploads
        |
        */

        'media' => [
            'driver' => 'local',
            'root' => env(
                'MEDIA_STORAGE_PATH',
                storage_path('app/media')
            ),
            'throw' => true,
            'report' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Tenant media is never linked into public/; it is intentionally absent.
    |
    */

    'links' => [],

];
