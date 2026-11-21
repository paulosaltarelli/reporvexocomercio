<?php
use App\Http\Controllers\DeliveryController;
use Illuminate\Support\Facades\Route;

// Loaded inside ResolveTenant + TenantAuthenticated + EnforceContractAccess. No public webhooks.
Route::prefix('integracoes-pedidos')->name('delivery.')->group(function () {
    Route::get('/',[DeliveryController::class,'index'])->name('index');
    Route::post('provedores/{provider}',[DeliveryController::class,'settings'])->whereIn('provider',['ifood','aiqfome','uairango'])->name('settings');
    Route::post('provedores/{provider}/catalogo',[DeliveryController::class,'mapping'])->whereIn('provider',['ifood','aiqfome','uairango'])->name('mapping');
    Route::post('importar-teste',[DeliveryController::class,'import'])->middleware('throttle:20,1')->name('import');
    Route::get('pedidos/{order}',[DeliveryController::class,'show'])->whereNumber('order')->name('show');
    Route::post('pedidos/{order}/etapa',[DeliveryController::class,'transition'])->whereNumber('order')->name('transition');
    Route::post('pedidos/{order}/itens/{item}/producao',[DeliveryController::class,'productionItem'])->whereNumber(['order','item'])->name('items.production');
    Route::post('eventos/{event}/tentar',[DeliveryController::class,'retry'])->whereNumber('event')->middleware('throttle:5,1')->name('retry');
});
