<?php

namespace App\Services;

use App\Models\PriceChange;
use App\Models\PriceReview;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PricingService
{
    public function syncProduct(int $productId, int $userId, string $source, ?string $previousPrice = null): Product
    {
        $this->validateSource($source);

        return DB::connection('tenant')->transaction(function () use ($productId, $userId, $source, $previousPrice) {
            $product = Product::lockForUpdate()->findOrFail($productId);
            $before = Decimal::input($previousPrice ?? (string) $product->sale_price, 2, 'sale_price');
            $context = $this->context($product);
            $after = Decimal::input((string) $product->sale_price, 2, 'sale_price');
            if ($context['mode'] === 'automatic' && $context['state'] === 'overflow') {
                throw ValidationException::withMessages(['sale_price' => 'O preço calculado ultrapassa o limite suportado. Ajuste a margem antes de continuar; nenhuma alteração desta operação foi aplicada.']);
            }
            if ($context['mode'] === 'automatic' && $context['state'] === 'ready') {
                $after = $context['suggested_price'];
            }
            if (bccomp($product->sale_price, $after, 2) !== 0) {
                $product->update(['sale_price' => $after]);
            }
            if (bccomp($before, $after, 2) !== 0) {
                $this->record($product, $userId, $before, $after, $context, $source);
            }
            $review = $this->review($product, $context);
            $product->setRelation('priceReview', $review);

            return $product;
        }, 3);
    }

    public function syncGroup(int $groupId, int $userId): void
    {
        DB::connection('tenant')->transaction(function () use ($groupId, $userId) {
            ProductGroup::whereKey($groupId)->sharedLock()->firstOrFail();
            // Stable ordering prevents product/product lock inversion between groups.
            $products = Product::where('product_group_id', $groupId)->orderBy('id')->lockForUpdate()->get();
            foreach ($products as $product) {
                $this->syncProduct($product->id, $userId, 'group:'.$groupId);
            }
        }, 3);
    }

    public function applySuggested(int $productId, int $userId, string $expectedPrice, string $requestKey): Product
    {
        if (! Str::isUuid($requestKey)) {
            throw ValidationException::withMessages(['request_key' => 'Chave da revisão de preço inválida.']);
        }
        $expectedPrice = Decimal::input($expectedPrice, 2, 'expected_price');

        try {
            return DB::connection('tenant')->transaction(function () use ($productId, $userId, $expectedPrice, $requestKey) {
                $product = Product::lockForUpdate()->findOrFail($productId);
                $existing = PriceChange::where('request_key', $requestKey)->lockForUpdate()->first();
                if ($existing) {
                    $this->validateReplay($existing, $productId, $userId, $expectedPrice);
                    return $product;
                }
                if (! $product->active) {
                    throw ValidationException::withMessages(['sale_price' => 'Ative o produto antes de aplicar uma sugestão de preço.']);
                }
                $context = $this->context($product);
                if ($context['state'] !== 'ready') {
                    throw ValidationException::withMessages(['sale_price' => $context['state'] === 'no_cost'
                        ? 'Informe um custo positivo na base utilizada antes de aplicar a sugestão.'
                        : 'O preço calculado ultrapassa o limite suportado. Ajuste a margem.']);
                }
                $suggestion = $context['suggested_price'];
                if (bccomp($suggestion, $expectedPrice, 2) !== 0) {
                    throw ValidationException::withMessages(['expected_price' => 'A sugestão de preço mudou desde a revisão. Atualize a página e confira o novo valor.']);
                }
                $before = Decimal::input((string) $product->sale_price, 2, 'sale_price');
                if (bccomp($before, $suggestion, 2) === 0) {
                    throw ValidationException::withMessages(['sale_price' => 'O preço já coincide com a sugestão atual.']);
                }
                $product->update(['sale_price' => $suggestion]);
                $this->record($product, $userId, $before, $suggestion, $context, 'review:apply', $requestKey);
                $product->setRelation('priceReview', $this->review($product, $context));

                return $product;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = PriceChange::where('request_key', $requestKey)->lockForUpdate()->first();
            if ($existing) {
                $this->validateReplay($existing, $productId, $userId, $expectedPrice);
                return Product::findOrFail($productId);
            }

            throw $exception;
        }
    }

    private function context(Product $product): array
    {
        // Locking read sees current configuration and any group edits in this transaction.
        // Callers changing group configuration must wrap save + sync in retryable transaction.
        $group = ProductGroup::whereKey($product->product_group_id)->sharedLock()->firstOrFail();
        $mode = $product->price_policy ?? $group->price_policy;
        if (! in_array($mode, ['alert', 'automatic'], true)) {
            throw ValidationException::withMessages(['price_policy' => 'Política de preço inválida.']);
        }
        $margin = Decimal::input((string) ($product->margin ?? $group->margin), 2, 'margin', '0', '99.99');
        $cost = Decimal::input($product->pricingCost(), 4, $product->controlsStock() ? 'average_cost' : 'reference_cost', '0', '999999999999.9999');
        $costSource = $product->controlsStock() ? 'average_cost' : 'reference_cost';
        $product->setRelation('group', $group);
        if (bccomp($cost, '0', 4) === 0) {
            return ['mode' => $mode, 'margin' => $margin, 'cost' => $cost, 'cost_source' => $costSource, 'state' => 'no_cost', 'suggested_price' => null];
        }
        $suggestion = Decimal::price($cost, $margin);
        if (bccomp($suggestion, '999999999999.99', 2) > 0) {
            return ['mode' => $mode, 'margin' => $margin, 'cost' => $cost, 'cost_source' => $costSource, 'state' => 'overflow', 'suggested_price' => null];
        }

        return ['mode' => $mode, 'margin' => $margin, 'cost' => $cost, 'cost_source' => $costSource, 'state' => 'ready', 'suggested_price' => $suggestion];
    }

    private function review(Product $product, array $context): PriceReview
    {
        $attention = $context['state'] === 'overflow'
            || ($context['state'] === 'ready' && bccomp($product->sale_price, $context['suggested_price'], 2) !== 0);

        return PriceReview::updateOrCreate(['product_id' => $product->id], [
            'suggested_price' => $context['suggested_price'], 'cost_snapshot' => $context['cost'], 'cost_source' => $context['cost_source'],
            'margin_snapshot' => $context['margin'], 'mode' => $context['mode'], 'state' => $context['state'],
            'needs_attention' => $attention, 'updated_at' => now(),
        ]);
    }

    private function record(Product $product, int $userId, string $before, string $after, array $context, string $source, ?string $requestKey = null): void
    {
        PriceChange::create([
            'product_id' => $product->id, 'user_id' => $userId,
            'before_price' => $before, 'after_price' => $after, 'average_cost' => $context['cost'], 'cost_source' => $context['cost_source'],
            'margin' => $context['margin'], 'mode' => $context['mode'], 'source' => $source, 'request_key' => $requestKey,
        ]);
    }

    private function validateReplay(PriceChange $change, int $productId, int $userId, string $price): void
    {
        if ((int) $change->product_id !== $productId || (int) $change->user_id !== $userId
            || $change->source !== 'review:apply' || bccomp($change->after_price, $price, 2) !== 0) {
            throw ValidationException::withMessages(['request_key' => 'Esta chave já pertence a outra aplicação de preço.']);
        }
    }

    private function validateSource(string $source): void
    {
        if (trim($source) === '' || mb_strlen($source) > 100) {
            throw ValidationException::withMessages(['source' => 'Origem da alteração de preço inválida.']);
        }
    }
}
