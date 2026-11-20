<?php

use App\Http\Controllers\Platform\AuthController;
use App\Http\Controllers\Platform\BankSettingsController;
use App\Http\Controllers\Platform\CompanyController;
use App\Http\Controllers\Platform\SettingsController;
use App\Http\Controllers\Platform\MailHistoryController;
use App\Http\Controllers\Platform\LicenseController;
use App\Http\Controllers\Platform\InstallerAccessController;
use App\Http\Middleware\PlatformAuthenticated;
use Illuminate\Support\Facades\Route;

// Included from web.php so cookies, sessions and CSRF retain the application's web middleware.
Route::prefix('vexo-admin')->group(function () {
    Route::get('login', [AuthController::class, 'form'])->name('platform.login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:30,1');
    Route::middleware(PlatformAuthenticated::class)->group(function () {
        Route::get('settings/banks', [BankSettingsController::class, 'index'])->name('platform.settings.banks.index');
        Route::post('settings/banks/{bank}/{environment}', [BankSettingsController::class, 'save'])
            ->middleware('throttle:15,1')->name('platform.settings.banks.save');
        Route::get('settings', [SettingsController::class,'index'])->name('platform.settings');
        Route::get('settings/emails', [MailHistoryController::class, 'index'])->name('platform.settings.emails.index');
        Route::get('settings/emails/{id}', [MailHistoryController::class, 'show'])->whereNumber('id')->name('platform.settings.emails.show');
        Route::post('settings/smtp', [SettingsController::class,'submit'])->middleware('throttle:10,1')->name('platform.settings.smtp.save');
        Route::post('settings/smtp/test', [SettingsController::class,'submit'])->middleware('throttle:10,1')->name('platform.settings.smtp.test');
        Route::get('admins',[\App\Http\Controllers\Platform\AdminController::class,'index'])->name('platform.admins');
        Route::post('admins',[\App\Http\Controllers\Platform\AdminController::class,'save'])->middleware('throttle:10,1')->name('platform.admins.create');
        Route::post('admins/{id}',[\App\Http\Controllers\Platform\AdminController::class,'save'])->whereNumber('id')->middleware('throttle:20,1')->name('platform.admins.update');
        Route::post('companies/{id}/support',[\App\Http\Controllers\Platform\SupportController::class,'enter'])->whereNumber('id')->middleware('throttle:20,1')->name('platform.support.enter');
        Route::post('support/leave',[\App\Http\Controllers\Platform\SupportController::class,'leave'])->name('platform.support.leave');
        Route::get('/', [CompanyController::class, 'index'])->name('platform.index');
        Route::get('financeiro', \App\Http\Controllers\Platform\ReceivablesController::class)->name('platform.receivables');
        Route::get('companies/new', [CompanyController::class, 'create'])->name('platform.companies.create');
        Route::post('companies', [CompanyController::class, 'store'])->middleware('throttle:10,1')->name('platform.companies.store');
        Route::post('requests/{id}/activation', [CompanyController::class, 'resendActivation'])->whereNumber('id')->middleware('throttle:3,10')->name('platform.requests.activation');
        Route::get('requests/{id}', [CompanyController::class, 'provisionRequest'])->whereNumber('id')->name('platform.requests.show');
        Route::prefix('companies/{id}/accesses')->whereNumber('id')->name('platform.company-access.')->group(function () {
            Route::get('/', [InstallerAccessController::class,'index'])->name('index');
            Route::post('/', [InstallerAccessController::class,'create'])->middleware('throttle:10,1')->name('create');
            Route::post('{invitation}/resend', [InstallerAccessController::class,'resend'])->whereNumber('invitation')->middleware('throttle:6,1')->name('resend');
            Route::post('{invitation}/update', [InstallerAccessController::class,'update'])->whereNumber('invitation')->middleware('throttle:15,1')->name('update');
            Route::post('{invitation}/revoke', [InstallerAccessController::class,'revoke'])->whereNumber('invitation')->middleware('throttle:15,1')->name('revoke');
        });
        Route::prefix('companies/{id}/license')->whereNumber('id')->name('platform.license.')->group(function () {
            Route::get('/', [LicenseController::class, 'index'])->name('index');
            Route::get('new', [LicenseController::class, 'create'])->name('create');
            Route::post('contracts', [LicenseController::class, 'store'])->middleware('throttle:15,1')->name('store');
            Route::get('contracts/{contract}/edit', [LicenseController::class, 'edit'])->whereNumber('contract')->name('edit');
            Route::post('contracts/{contract}', [LicenseController::class, 'update'])->whereNumber('contract')->middleware('throttle:15,1')->name('update');
        });
        Route::get('companies/{id}', [CompanyController::class, 'show'])->whereNumber('id')->name('platform.companies.show');
        Route::post('companies/{id}/status', [CompanyController::class, 'status'])->whereNumber('id')->middleware('throttle:20,1')->name('platform.companies.status');
        Route::post('logout', [AuthController::class, 'logout'])->name('platform.logout');
    });
});
