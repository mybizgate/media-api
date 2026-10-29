<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => config('media.service'),
        'version' => config('media.version'),
        'api' => url('/api/v1'),
        'health' => url('/api/v1/health'),
    ]);
});
