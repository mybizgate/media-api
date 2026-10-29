<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Media\ImageProcessingService;
use App\Services\Media\MediaPathService;
use App\Support\DeploymentInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(ImageProcessingService $images, MediaPathService $paths, DeploymentInfo $deployment): JsonResponse
    {
        $storage = $this->storageReady($paths);
        $gd = $images->available();
        $webp = $images->supportsWebp();

        $healthy = $storage && $gd && $webp;

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'service' => config('media.service'),
            'version' => config('media.version'),
            'environment' => app()->environment(),
            'deployment' => $deployment->summary(),
            'storage' => $storage,
            'gd' => $gd,
            'webp' => $webp,
            'avif' => $images->supportsAvif(),
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    private function storageReady(MediaPathService $paths): bool
    {
        try {
            $tenants = $paths->absolute($paths->tenantsDirectory());

            File::ensureDirectoryExists($tenants);

            return is_dir($tenants) && is_writable($tenants);
        } catch (Throwable) {
            return false;
        }
    }
}
