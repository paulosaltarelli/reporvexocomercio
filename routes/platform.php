<?php

use App\Http\Controllers\Platform\AuthController;
use App\Http\Controllers\Platform\CompanyController;
use App\Http\Middleware\PlatformAuthenticated;
use Illuminate\Support\Facades\Route;

// Included from web.php so cookies, sessions and CSRF retain the application's web middleware.
Route::prefix('vexo-admin')->group(function () {
    Route::get('login', [AuthController::class, 'form'])->name('platform.login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:30,1');
    Route::middleware(PlatformAuthenticated::class)->group(function () {
        Route::get('admins',[\App\Http\Controllers\Platform\AdminController::class,'index'])->name('platform.admins');
        Route::post('admins',[\App\Http\Controllers\Platform\AdminController::class,'save'])->middleware('throttle:10,1')->name('platform.admins.create');
        Route::post('admins/{id}',[\App\Http\Controllers\Platform\AdminController::class,'save'])->whereNumber('id')->middleware('throttle:20,1')->name('platform.admins.update');
        Route::post('companies/{id}/support',[\App\Http\Controllers\Platform\SupportController::class,'enter'])->whereNumber('id')->middleware('throttle:20,1')->name('platform.support.enter');
        Route::post('support/leave',[\App\Http\Controllers\Platform\SupportController::class,'leave'])->name('platform.support.leave');
        Route::get('/', [CompanyController::class, 'index'])->name('platform.index');
        Route::get('companies/new', [CompanyController::class, 'create'])->name('platform.companies.create');
        Route::post('companies', [CompanyController::class, 'store'])->middleware('throttle:10,1')->name('platform.companies.store');
        Route::get('requests/{id}', [CompanyController::class, 'provisionRequest'])->whereNumber('id')->name('platform.requests.show');
        Route::get('companies/{id}', [CompanyController::class, 'show'])->whereNumber('id')->name('platform.companies.show');
        Route::post('companies/{id}/status', [CompanyController::class, 'status'])->whereNumber('id')->middleware('throttle:20,1')->name('platform.companies.status');
        Route::post('logout', [AuthController::class, 'logout'])->name('platform.logout');
    });
});
