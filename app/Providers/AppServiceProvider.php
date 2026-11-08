<?php

namespace App\Providers;

use App\Services\Apps\AppRegistry;
use App\Services\Catalog\Catalog;
use App\Services\Docker\DockerClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped, not singleton: queue workers run for hours and reset scoped instances between
        // jobs, so a changed catalog is picked up by the next job.
        $this->app->scoped(Catalog::class, fn () => new Catalog((string) config('pk95.catalog_dir')));
        $this->app->scoped(AppRegistry::class);
        $this->app->singleton(DockerClient::class, fn () => new DockerClient(
            (string) config('pk95.docker.socket'),
            (string) config('pk95.docker.api_version', 'v1.44'),
            (int) config('pk95.docker.pull_timeout', 900),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
