<?php

use App\Http\Controllers\{SupermarketApprovalController, SupermarketDraftController, SupermarketHardwareController};
use Illuminate\Support\Facades\Route;

Route::prefix('supermarket')->name('supermarket.')->group(function () {
    Route::get('approvals',[SupermarketApprovalController::class,'index'])->name('approvals.index');
    Route::post('carts/{cart}/approvals',[SupermarketApprovalController::class,'request'])->whereNumber('cart')->name('approvals.request');
    Route::post('approvals/{approval}/decision',[SupermarketApprovalController::class,'decide'])->whereNumber('approval')->name('approvals.decide');
    Route::post('carts/{cart}/approvals/{approval}/apply',[SupermarketApprovalController::class,'apply'])->whereNumber(['cart','approval'])->name('approvals.apply');
    Route::post('carts/{cart}/drafts/preview',[SupermarketDraftController::class,'preview'])->whereNumber('cart')->name('drafts.preview');
    Route::post('carts/{cart}/drafts',[SupermarketDraftController::class,'store'])->whereNumber('cart')->name('drafts.import');
    Route::get('hardware',[SupermarketHardwareController::class,'index'])->name('hardware.index');
    Route::post('hardware/test',[SupermarketHardwareController::class,'test'])->name('hardware.test');
});
