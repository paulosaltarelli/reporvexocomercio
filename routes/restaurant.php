<?php

use App\Http\Controllers\RestaurantController;
use Illuminate\Support\Facades\Route;

Route::prefix('restaurante')->name('restaurant.')->group(function () {
    Route::get('/', [RestaurantController::class, 'index'])->name('index');
    Route::post('mesas', [RestaurantController::class, 'createTable'])->name('table.store');
    Route::post('comandas', [RestaurantController::class, 'open'])->name('orders.open');
    Route::get('producao', [RestaurantController::class, 'production'])->name('production');
    Route::get('comandas/{order}', [RestaurantController::class, 'show'])->whereNumber('order')->name('orders.show');
    Route::post('comandas/{order}/itens', [RestaurantController::class, 'addItem'])->whereNumber('order')->name('orders.items.add');
    Route::post('comandas/{order}/enviar', [RestaurantController::class, 'send'])->whereNumber('order')->name('orders.send');
    Route::post('comandas/{order}/itens/{item}/etapa', [RestaurantController::class, 'transitionItem'])->whereNumber(['order', 'item'])->name('items.status');
    Route::post('comandas/{order}/itens/{item}/entregar', [RestaurantController::class, 'deliverItem'])->whereNumber(['order', 'item'])->name('items.deliver');
    Route::post('comandas/{order}/itens/{item}/cancelar', [RestaurantController::class, 'cancelItem'])->whereNumber(['order', 'item'])->name('items.cancel');
    Route::get('comandas/{order}/comprovante', [RestaurantController::class, 'receipt'])->whereNumber('order')->name('orders.receipt');
    Route::post('comandas/{order}/fechar', [RestaurantController::class, 'close'])->whereNumber('order')->name('orders.close');
    Route::post('comandas/{order}/cancelar', [RestaurantController::class, 'cancel'])->whereNumber('order')->name('orders.cancel');
});
