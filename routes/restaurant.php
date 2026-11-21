<?php

use App\Http\Controllers\RestaurantController;
use Illuminate\Support\Facades\Route;

Route::prefix('restaurante')->name('restaurant.')->group(function () {
    Route::get('atividade', [\App\Http\Controllers\RestaurantActivityController::class,'show'])->name('activity');
    Route::get('catalogo', [\App\Http\Controllers\RestaurantCatalogController::class,'index'])->name('catalog.index');
    Route::get('catalogo/produtos/{product}', [\App\Http\Controllers\RestaurantCatalogController::class,'edit'])->whereNumber('product')->name('catalog.edit');
    Route::post('catalogo/produtos/{product}', [\App\Http\Controllers\RestaurantCatalogController::class,'save'])->whereNumber('product')->name('catalog.save');
    Route::post('comandas/{order}/divisoes', [\App\Http\Controllers\RestaurantPaymentController::class,'divide'])->whereNumber('order')->name('divisions.store');
    Route::post('comandas/{order}/divisoes/{division}/cancelar', [\App\Http\Controllers\RestaurantPaymentController::class,'cancelDivision'])->whereNumber('order')->whereUuid('division')->name('divisions.cancel');
    Route::post('comandas/{order}/recebimentos', [\App\Http\Controllers\RestaurantPaymentController::class,'store'])->whereNumber('order')->name('payments.store');
    Route::post('comandas/{order}/recebimentos/{payment}/estornar', [\App\Http\Controllers\RestaurantPaymentController::class,'refund'])->whereNumber(['order','payment'])->name('payments.refund');

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
    Route::post('comandas/{order}/transferir', [RestaurantController::class, 'transfer'])->whereNumber('order')->name('orders.transfer');
    Route::get('comandas/{order}/pre-conta', [RestaurantController::class, 'prebill'])->whereNumber('order')->name('orders.prebill');
    Route::get('comandas/{order}/ticket', [RestaurantController::class, 'ticket'])->whereNumber('order')->name('orders.ticket');
    Route::post('comandas/{order}/cancelar', [RestaurantController::class, 'cancel'])->whereNumber('order')->name('orders.cancel');
});
