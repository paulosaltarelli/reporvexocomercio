<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\Seller;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CommercialRules
{
    /** Legacy user ID remains parameter two; the master salesperson ID is parameter three. */
    public function seller(int $actorId, ?int $legacyUserId = null, ?int $salespersonId = null): Seller
    {
        $actor = User::where('active', true)->sharedLock()->find($actorId);
        if (! $actor) throw ValidationException::withMessages(['salesperson_id' => 'O usuário responsável precisa estar ativo.']);
        if ($actor->role !== 'admin') {
            if ($legacyUserId !== null && $legacyUserId !== $actorId) {
                throw ValidationException::withMessages(['salesperson_id' => 'Operadores só podem escolher seu próprio perfil de vendedor.']);
            }
            $own = $this->profileFor($actor);
            if ($salespersonId !== null && $salespersonId !== $own->id) {
                throw ValidationException::withMessages(['salesperson_id' => 'Operadores só podem escolher seu próprio perfil de vendedor.']);
            }
            return $this->activeSeller($own);
        }
        if ($salespersonId !== null) {
            $seller = Seller::whereKey($salespersonId)->sharedLock()->first();
            if (! $seller) throw ValidationException::withMessages(['salesperson_id' => 'Selecione um vendedor ativo.']);
            if ($legacyUserId !== null) {
                $legacy = $this->legacyProfile($legacyUserId);
                if ($legacy->id !== $seller->id) {
                    throw ValidationException::withMessages(['salesperson_id' => 'Os identificadores de vendedor e usuário apontam para pessoas diferentes.']);
                }
            }
            return $this->activeSeller($seller);
        }

        return $this->activeSeller($legacyUserId !== null ? $this->legacyProfile($legacyUserId) : $this->profileFor($actor));
    }

    private function legacyProfile(int $userId): Seller
    {
        $user = User::whereKey($userId)->sharedLock()->first();
        if (! $user) throw ValidationException::withMessages(['seller_id' => 'O usuário informado não possui um perfil de vendedor válido.']);

        return $this->profileFor($user);
    }

    private function profileFor(User $user): Seller
    {
        $existing = Seller::where('user_id', $user->id)->sharedLock()->first();
        if ($existing) return $existing; // Never rename or reactivate an existing master profile implicitly.
        try {
            return Seller::create(['user_id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'active' => (bool) $user->active]);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = Seller::where('user_id', $user->id)->lockForUpdate()->first();
            if ($existing) return $existing;
            throw $exception;
        }
    }

    private function activeSeller(Seller $seller): Seller
    {
        if (! $seller->active) throw ValidationException::withMessages(['salesperson_id' => 'O perfil de vendedor está inativo. Solicite a revisão de um administrador.']);

        return $seller;
    }

    public function quote(Product|Service $item, array $line): array
    {
        $policy = $this->policy($item);
        $catalog = Decimal::input((string) ($item instanceof Product ? $item->sale_price : $item->price), 2, 'items');
        if (isset($line['catalog_price_seen']) && bccomp(Decimal::input((string) $line['catalog_price_seen'], 2, 'items'), $catalog, 2) !== 0) {
            throw ValidationException::withMessages(['items' => 'O preço de cadastro mudou. Atualize a seleção e confira o item antes de concluir.']);
        }

        return $this->calculate($catalog, (string) $line['unit_price'], (string) $line['quantity'],
            (string) ($line['discount_percent'] ?? '0'), $policy['enabled'], $policy['limit'], $policy['commission']);
    }

    /** Server-only approved document snapshot; this method is never selected by request flags. */
    public function fromDocument(Product|Service $item, array $line, int $version): array
    {
        $policy = $this->policy($item);
        if ($version === 0) {
            // Legacy agreed prices are historical baselines, not newly inferred discounts.
            $terms = $this->calculate((string) $line['unit_price'], (string) $line['unit_price'],
                (string) $line['quantity'], '0', false, '0', $policy['commission']);
        } elseif ($version === 1) {
            foreach (['catalog_unit_price', 'offered_unit_price', 'explicit_discount_percent', 'quantity', 'discount_allowed', 'discount_limit_percent', 'gross_total', 'discount_amount', 'total', 'discount_percent', 'unit_price'] as $field) {
                if (! array_key_exists($field, $line)) {
                    throw ValidationException::withMessages(['items' => 'O documento não possui um snapshot comercial completo.']);
                }
            }
            if (! in_array($line['discount_allowed'], [true, false, 0, 1], true)) {
                throw ValidationException::withMessages(['items' => 'O snapshot de autorização de desconto é inválido.']);
            }
            $terms = $this->calculate((string) $line['catalog_unit_price'], (string) $line['offered_unit_price'],
                (string) $line['quantity'], (string) $line['explicit_discount_percent'],
                (bool) $line['discount_allowed'], (string) $line['discount_limit_percent'], $policy['commission']);
            foreach (['gross_total', 'discount_amount', 'total', 'unit_price', 'discount_percent'] as $field) {
                $scale = $field === 'discount_percent' ? 4 : 2;
                if (bccomp((string) $line[$field], $terms[$field], $scale) !== 0) {
                    throw ValidationException::withMessages(['items' => 'Os valores do documento não correspondem ao snapshot comercial aprovado.']);
                }
            }
        } else {
            throw ValidationException::withMessages(['items' => 'Versão comercial do documento não suportada.']);
        }
        if (isset($line['total']) && bccomp((string) $line['total'], $terms['total'], 2) !== 0) {
            throw ValidationException::withMessages(['items' => 'O total do documento não corresponde aos itens aprovados.']);
        }

        return $terms;
    }

    private function policy(Product|Service $item): array
    {
        if (! $item instanceof Product) return ['enabled' => false, 'limit' => '0.00', 'commission' => '0.00'];
        $group = ProductGroup::whereKey($item->product_group_id)->sharedLock()->firstOrFail();

        return ['enabled' => (bool) ($item->discount_enabled ?? $group->discount_enabled),
            'limit' => Decimal::input((string) ($item->max_discount_percent ?? $group->max_discount_percent), 2, 'max_discount_percent', '0', '100'),
            'commission' => Decimal::input((string) ($item->commission_percent ?? $group->commission_percent), 2, 'commission_percent', '0', '100')];
    }

    private function calculate(string $catalog, string $offered, string $quantity, string $explicit, bool $enabled, string $limit, string $commission): array
    {
        $catalog = Decimal::input($catalog, 2, 'items');
        $offered = Decimal::input($offered, 2, 'items');
        $quantity = Decimal::input($quantity, 3, 'items', '0.001', '999999');
        $explicit = Decimal::input($explicit, 2, 'items', '0', '100');
        $limit = Decimal::input($limit, 2, 'items', '0', '100');
        $base = bccomp($catalog, $offered, 2) > 0 ? $catalog : $offered;
        $factor = bcsub('1', bcdiv($explicit, '100', 8), 8);
        $offeredNetUnit = bcmul($offered, $factor, 8);
        $unitReduction = bcsub($base, $offeredNetUnit, 8);
        if ((! $enabled && (bccomp($unitReduction, '0', 8) > 0 || bccomp($explicit, '0', 2) > 0))
            || bccomp($explicit, $limit, 2) > 0
            || bccomp(bcmul($unitReduction, '100', 8), bcmul($base, $limit, 8), 8) > 0) {
            throw ValidationException::withMessages(['items' => 'O desconto total do item, incluindo redução do preço unitário, não é permitido ou ultrapassa o limite configurado.']);
        }
        $gross = Decimal::input(Decimal::round(bcmul($quantity, $base, 8)), 2, 'items');
        $net = Decimal::input(Decimal::round(bcmul($quantity, $offeredNetUnit, 8)), 2, 'items');
        $discount = bcsub($gross, $net, 2);
        // Cent rounding cannot make the actual charged discount exceed the policy.
        if (bccomp(bcmul($discount, '100', 8), bcmul($gross, $limit, 8), 8) > 0) {
            throw ValidationException::withMessages(['items' => 'O desconto arredondado para centavos ultrapassa o limite deste item.']);
        }
        $effective = bccomp($gross, '0', 2) > 0 ? Decimal::round(bcdiv(bcmul($discount, '100', 8), $gross, 8), 4) : '0.0000';
        $fee = Decimal::input(Decimal::round(bcdiv(bcmul($net, $commission, 8), '100', 8)), 2, 'items');

        return ['quantity' => $quantity, 'unit_price' => $base, 'catalog_unit_price' => $catalog, 'offered_unit_price' => $offered,
            'explicit_discount_percent' => $explicit, 'discount_percent' => $effective,
            'gross_total' => $gross, 'discount_amount' => $discount, 'total' => $net,
            'discount_allowed' => $enabled, 'discount_limit_percent' => $limit,
            'commission_rate' => $commission, 'commission_amount' => $fee];
    }
}
