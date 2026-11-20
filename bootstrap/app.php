<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([\App\Platform\Commands\PlatformAdminCommand::class, \App\Platform\Commands\PlatformMfaCommand::class, \App\Platform\Commands\PlatformProvisionCommand::class, \App\Platform\Commands\PlatformProvisionWorkCommand::class])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [\App\Http\Middleware\CompanyHost::class]);
        $middleware->trimStrings(except: ['certificate_password','csc','emission_token','management_token','admin_password','db_password','bootstrap_password']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'certificate', 'certificate_password', 'pfx', 'csc', 'csc_id', 'token', 'emission_token', 'management_token', 'secret_reference','db_password','admin_password','admin_password_confirmation','bootstrap_password','controlplane_token','recovery_key','password_hash','mfa_code','activation_code']);
    })->create();
