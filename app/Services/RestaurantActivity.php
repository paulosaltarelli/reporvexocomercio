<?php

namespace App\Services;

use App\Models\{RestaurantOrder, RestaurantOrderItem, RestaurantTable};
use Illuminate\Support\Collection;

/** Revisions are derived from the same rows shown on screen, without exposing their data. */
final class RestaurantActivity
{
    private function rows(Collection $records, array $fields): array
    {
        return $records->sortBy('id')->values()->map(function ($record) use ($fields) {
            $row = [];
            foreach ($fields as $field) {
                $value = $record->getRawOriginal($field);
                $row[$field] = $value === null ? null : (string)$value;
            }
            return $row;
        })->all();
    }

    private function state(string $view, array $parts, Collection $records): array
    {
        $updated = $records->map(fn ($record) => $record->getRawOriginal('updated_at'))->filter()->sort()->last();
        return ['version' => hash('sha256', json_encode([$view, $parts], JSON_THROW_ON_ERROR)),
            'updated_at' => $updated ? \Illuminate\Support\Carbon::parse($updated)->toIso8601String() : null];
    }

    public function index(Collection $tables, Collection $orders): array
    {
        return $this->state('index', [
            $this->rows($tables, ['id','name','active','updated_at']),
            $this->rows($orders, ['id','restaurant_table_id','customer_id','status','version','updated_at']),
        ], $tables->concat($orders));
    }

    public function order(RestaurantOrder $order, Collection $products, array $catalog): array
    {
        return $this->state('order', [
            $this->rows(collect([$order]), ['id','restaurant_table_id','customer_id','status','version','updated_at']),
            $this->rows($order->items, ['id','status','station','quantity','unit_price','updated_at']),
            $this->rows($products, ['id','active','name','sku','barcode','sale_price','updated_at']), $catalog,
        ], collect([$order])->concat($order->items)->concat($products));
    }

    public function production(Collection $items): array
    {
        return $this->state('production', [
            $this->rows($items, ['id','restaurant_order_id','status','station','sent_at','updated_at']),
            $this->rows($items->pluck('order')->filter()->unique('id'), ['id','restaurant_table_id','status','version','updated_at']),
        ], $items);
    }

    public function current(string $view, ?int $orderId = null, ?string $station = null): array
    {
        if ($view === 'index') return $this->index(RestaurantTable::where('active',true)->get(), RestaurantOrder::where('status','open')->get());
        if ($view === 'order') {
            $order = RestaurantOrder::with('items')->findOrFail($orderId);
            $products = app(RestaurantCatalog::class)->menuQuery()->with('group')->orderBy('name')->get();
            return $this->order($order, $products, app(RestaurantCatalog::class)->publicCatalog($products));
        }
        return $this->production(RestaurantOrderItem::with('order.table')->whereIn('status',['queued','preparing','ready'])
            ->whereHas('order',fn ($query) => $query->where('status','open'))
            ->when($station, fn ($query) => $query->where('station',$station))->get());
    }
}
