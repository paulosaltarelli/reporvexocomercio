<?php

use App\Http\Controllers\SupermarketController;
use App\Http\Controllers\SupermarketShiftController;
use Illuminate\Support\Facades\Route;

Route::prefix('supermarket')->name('supermarket.')->controller(SupermarketController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('shifts', [SupermarketShiftController::class, 'open'])->name('shifts.open');
    Route::post('shifts/{shift}/close', [SupermarketShiftController::class, 'close'])->whereNumber('shift')->name('shifts.close');
    Route::get('lookup', 'lookup')->name('lookup');
    Route::post('carts', 'start')->name('start');
    Route::get('carts/{cart}', 'show')->whereNumber('cart')->name('show');
    Route::get('carts/{cart}/products', 'products')->whereNumber('cart')->name('products');
    Route::post('carts/{cart}/items', 'addItem')->whereNumber('cart')->name('items.add');
    Route::post('carts/{cart}/items/{line}/quantity', 'updateItem')->whereNumber('cart')->whereUuid('line')->name('items.update');
    Route::post('carts/{cart}/items/{line}/remove', 'removeItem')->whereNumber('cart')->whereUuid('line')->name('items.remove');
    Route::post('carts/{cart}/suspend', 'suspend')->whereNumber('cart')->name('suspend');
    Route::post('carts/{cart}/resume', 'resume')->whereNumber('cart')->name('resume');
    Route::post('carts/{cart}/cancel', 'cancel')->whereNumber('cart')->name('cancel');
    Route::get('carts/{cart}/receipt', 'receipt')->whereNumber('cart')->name('receipt');
    Route::post('carts/{cart}/checkout', 'checkout')->whereNumber('cart')->name('checkout');
});
