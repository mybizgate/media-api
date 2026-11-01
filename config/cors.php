<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| Tenant frontends run on their own origins (Vite dev server, each
| <tenant>.mybizgate.com subdomain). Additional origins can be added with
| MEDIA_CORS_ORIGINS as a comma-separated list, without changing code.
|
*/

$extraOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('MEDIA_CORS_ORIGINS', ''))
)));

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_merge([
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'https://lzhub.mybizgate.com',
    ], $extraOrigins),

    // Any https MyBizGate subdomain at any depth (tenant sites, www.<tenant>,
    // demos, staging). Anchored on the full host, so look-alike domains such
    // as mybizgate.com.evil.com or evil-mybizgate.com are rejected.
    'allowed_origins_patterns' => [
        '#^https://([a-z0-9-]+\.)+mybizgate\.com$#',
    ],

    'allowed_headers' => ['Accept', 'Content-Type', 'X-Client-Code', 'Authorization', 'X-Requested-With'],

    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
