<?php

namespace App\Models;

use App\Services\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Product extends Model
{
    protected $connection = 'tenant';

    protected $table = 'products';

    protected $guarded = ['id'];

    protected $attributes = ['type' => 'product', 'controls_stock' => true, 'reference_cost' => '0.0000'];

    protected function casts(): array
    {
        return ['controls_stock' => 'boolean', 'active' => 'boolean', 'discount_enabled' => 'boolean'];
    }

    public function discountEnabled(): bool
    {
        return (bool) ($this->discount_enabled ?? $this->group->discount_enabled ?? false);
    }

    public function maxDiscountPercent(): string
    {
        return (string) ($this->max_discount_percent ?? $this->group->max_discount_percent ?? '0.00');
    }

    public function commissionPercent(): string
    {
        return (string) ($this->commission_percent ?? $this->group->commission_percent ?? '0.00');
    }

    public function controlsStock(): bool
    {
        return $this->type === 'product' && (bool) $this->controls_stock;
    }

    public function pricingCost(): string
    {
        return (string) (($this->controlsStock() ? $this->average_cost : $this->reference_cost) ?? '0.0000');
    }

    /** Compatibility for old payloads; archived Service rows never bypass current catalog controls. */
    public static function resolveLegacyService(int $serviceId, bool $lock = false): self|Service
    {
        $query = self::where('legacy_service_id', $serviceId);
        $mapped = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($mapped) {
            if ($mapped->type !== 'service' || ! $mapped->active) {
                throw ValidationException::withMessages(['items' => 'O serviço legado foi alterado ou desativado. Selecione o item pelo cadastro atual.']);
            }
            return $mapped;
        }
        $legacy = Service::where('active', true);

        return ($lock ? $legacy->lockForUpdate() : $legacy)->findOrFail($serviceId);
    }

    public function save(array $options = []): bool
    {
        // Keep the balance check and type/control change atomic even for direct ORM callers.
        return DB::connection($this->getConnectionName())->transaction(fn () => parent::save($options), 3);
    }

    protected static function booted(): void
    {
        static::saving(function (self $product): void {
            if (! in_array($product->type, ['product', 'service'], true)) {
                throw ValidationException::withMessages(['type' => 'Tipo de item inválido.']);
            }
            if ($product->type === 'service') {
                $product->controls_stock = false;
            }
            Decimal::input((string) ($product->reference_cost ?? '0.0000'), 4, 'reference_cost', '0', '999999999999.9999');
            $current = $product->exists ? self::whereKey($product->getKey())->lockForUpdate()->firstOrFail() : null;
            if ($current && ($product->isDirty('type') || ($current->controlsStock() && ! $product->controlsStock()))
                && bccomp((string) $current->quantity, '0', 3) !== 0) {
                throw ValidationException::withMessages(['controls_stock' => 'Zere o saldo por movimentações de estoque antes de alterar o tipo ou desativar o controle.']);
            }
            $quantity = (! $current || $product->isDirty('quantity'))
                ? (string) ($product->quantity ?? '0.000') : (string) $current->quantity;
            Decimal::input($quantity, 3, 'quantity', '0', '9999999999999.999');
            if (! $product->controlsStock() && bccomp($quantity, '0', 3) !== 0) {
                throw ValidationException::withMessages(['quantity' => 'Itens sem controle de estoque devem permanecer com saldo zero.']);
            }
        });
    }

    public function priceReview()
    {
        return $this->hasOne(PriceReview::class);
    }

    public function priceChanges()
    {
        return $this->hasMany(PriceChange::class);
    }

    public function group()
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }
}
