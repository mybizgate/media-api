<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MediaController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::get('/health', HealthController::class);

    // Dummy route used to verify that a deployment reached production.
    Route::get('/ping', fn () => response()->json([
        'success' => true,
        'data' => ['pong' => true, 'time' => now()->toIso8601String()],
    ]))->name('ping');

    // Read endpoints: public media needs no header; private media and tenant
    // scoping use X-Client-Code when it is present (see MediaController).
    Route::get('/media/{mediaId}', [MediaController::class, 'show'])
        ->where('mediaId', MediaController::ID_PATTERN)
        ->name('media.show');

    Route::get('/media/{mediaId}/thumbnail', [MediaController::class, 'thumbnail'])
        ->where('mediaId', MediaController::ID_PATTERN)
        ->name('media.thumbnail');

    Route::get('/media/{mediaId}/metadata', [MediaController::class, 'metadata'])
        ->where('mediaId', MediaController::ID_PATTERN)
        ->name('media.metadata');

    Route::get('/media/{mediaId}/download', [MediaController::class, 'download'])
        ->where('mediaId', MediaController::ID_PATTERN)
        ->name('media.download');

    // Tenant-scoped endpoints: X-Client-Code is mandatory.
    Route::middleware('tenant')->group(function () {

        Route::get('/media', [MediaController::class, 'index'])->name('media.index');

        Route::post('/media', [MediaController::class, 'store'])->name('media.store');

        // PUT is the canonical verb. PHP only parses multipart bodies for POST, so
        // browsers may send POST (optionally with _method=PUT) to the same URL.
        Route::match(['PUT', 'POST'], '/media/{mediaId}', [MediaController::class, 'update'])
            ->where('mediaId', MediaController::ID_PATTERN)
            ->name('media.update');

        // Metadata-only update: link the media to a business record after the
        // fact (e.g. a product created once its photo was already uploaded).
        Route::patch('/media/{mediaId}', [MediaController::class, 'assign'])
            ->where('mediaId', MediaController::ID_PATTERN)
            ->name('media.assign');

        Route::delete('/media/{mediaId}', [MediaController::class, 'destroy'])
            ->where('mediaId', MediaController::ID_PATTERN)
            ->name('media.destroy');
    });

});
