<?php

namespace App\Providers;

use App\Repositories\Media\FilesystemMediaRepository;
use App\Repositories\Media\MediaRepositoryInterface;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Swap this binding for a database-backed repository when one exists;
        // controllers and services only depend on the interface.
        $this->app->singleton(MediaRepositoryInterface::class, FilesystemMediaRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Generated view/download URLs must match the public API host even
        // behind the shared-hosting proxy, so anchor them to APP_URL.
        $url = (string) config('app.url');

        if ($url !== '' && ! $this->app->runningUnitTests()) {
            URL::forceRootUrl($url);

            if ($scheme = parse_url($url, PHP_URL_SCHEME)) {
                URL::forceScheme($scheme);
            }
        }
    }
}
