<?php

namespace App\Http\Controllers;

use App\Services\{InstallerAccess, OperationalModes, RestaurantActivity};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class RestaurantActivityController extends Controller
{
    public function show(Request $request, RestaurantActivity $activity)
    {
        app(OperationalModes::class)->require('restaurant');
        $data = $request->validate(['view'=>'required|in:index,order,production',
            'order_id'=>'required_if:view,order|nullable|integer|min:1', 'station'=>'nullable|in:kitchen,bar']);
        $userId = (int)$request->attributes->get('currentUser')->id;
        InstallerAccess::authorizedActor($userId, $data['view']==='production' ? 'restaurant.kitchen' : 'restaurant.use');
        $key = 'restaurant-activity:'.$request->attributes->get('tenant')->id.':'.$userId;
        if (RateLimiter::tooManyAttempts($key,30)) return response()->json(['message'=>'Aguarde para atualizar novamente.'],429)->header('Retry-After','10')->header('Cache-Control','no-store');
        RateLimiter::hit($key,60);
        return response()->json($activity->current($data['view'], isset($data['order_id']) ? (int)$data['order_id'] : null, $data['station']??null))
            ->header('Cache-Control','private, no-store');
    }
}
