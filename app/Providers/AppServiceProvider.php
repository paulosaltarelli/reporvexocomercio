<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Platform\Billing\C6Gateway::class, \App\Platform\Billing\C6Bank::class);
        if (PHP_SAPI==='cli' && function_exists('posix_geteuid') && posix_geteuid()===0) {
            $this->app->bind(\App\Platform\TenantStorageProvisioner::class, fn()=>new \App\Tenancy\MysqlTenantStorageProvisioner(
                \App\Tenancy\ControlPlane::pdo(),\App\Tenancy\SharedManifestFile::load()
            ));
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::defaultView('components.store-pagination');
    }
}
