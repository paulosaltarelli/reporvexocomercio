<?php

namespace App\Fiscal;

use App\Models\FiscalItemRule;
use App\Models\FiscalTaxProfile;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Selected but unavailable rules never fall through to a lower-precedence rule. */
final class FiscalRuleResolver
{
    public function resolve(Product|int $product, bool $lock = false): ?array
    {
        $query = Product::whereKey(is_int($product) ? $product : $product->id);
        $fresh = ($lock ? $query->sharedLock() : $query)->firstOrFail();
        if ($product instanceof Product) { $product->setRawAttributes($fresh->getAttributes(), true); }
        else { $product = $fresh; }
        $query = FiscalItemRule::where('product_id', $product->id);
        $individual = ($lock ? $query->sharedLock() : $query)->first();
        if ($individual) { return $this->evaluate($product, $individual, null, null); }
        $group = null;
        if (! $product->fiscal_tax_profile_id && $product->product_group_id) {
            $query = ProductGroup::whereKey($product->product_group_id);
            $group = ($lock ? $query->sharedLock() : $query)->first();
        }
        $profileId = $product->fiscal_tax_profile_id ?: $group?->fiscal_tax_profile_id;
        $profile = null;
        if ($profileId) {
            $query = FiscalTaxProfile::whereKey($profileId);
            $profile = ($lock ? $query->sharedLock() : $query)->first();
        }
        return $this->evaluate($product, null, $group, $profile);
    }

    /** Three batched lookups, no static cache or cross-tenant retained state. @return array<int,?array> */
    public function resolveMany(Collection $products): array
    {
        if ($products->isEmpty()) { return []; }
        $rules = FiscalItemRule::whereIn('product_id', $products->pluck('id'))->get()->keyBy('product_id');
        $groups = ProductGroup::whereIn('id', $products->pluck('product_group_id')->filter()->unique())->get()->keyBy('id');
        $profileIds = $products->pluck('fiscal_tax_profile_id')->merge($groups->pluck('fiscal_tax_profile_id'))->filter()->unique();
        $profiles = FiscalTaxProfile::whereIn('id', $profileIds)->get()->keyBy('id');
        $resolved = [];
        foreach ($products as $product) {
            $group = $groups->get($product->product_group_id);
            $profileId = $product->fiscal_tax_profile_id ?: $group?->fiscal_tax_profile_id;
            $resolved[$product->id] = $this->evaluate($product, $rules->get($product->id), $group, $profiles->get($profileId));
        }
        return $resolved;
    }

    public function explain(?array $rule): string
    {
        if (! $rule) { return 'Associe um perfil tributário ao produto ou ao grupo, ou cadastre uma exceção individual.'; }
        return match ($rule['unavailable_reason'] ?? null) {
            'inactive_profile' => 'O perfil tributário selecionado está inativo. Reative-o ou associe outro perfil validado.',
            'invalid_profile' => 'Os parâmetros do perfil tributário precisam de revisão.',
            'missing_validation' => 'Falta referência da validação contábil da regra tributária.',
            'invalid_product_identity' => 'Complete e valide o GTIN próprio do produto (ou informe SEM GTIN explicitamente) e confira o CEST.',
            'invalid_individual_rule' => 'A exceção tributária individual precisa de revisão.',
            default => ($rule['available'] ?? false) ? 'Regra tributária disponível para conferência da operação.' : 'A configuração tributária selecionada precisa de revisão.',
        };
    }

    private function evaluate(Product $product, ?FiscalItemRule $individual, ?ProductGroup $group, ?FiscalTaxProfile $profile): ?array
    {
        $result = [
            'parameters' => [], 'accountant_reference' => null, 'validated_at' => null,
            'source' => null, 'profile_id' => null, 'profile_revision' => null, 'profile_name' => null,
            'group_id' => $product->product_group_id, 'available' => true, 'unavailable_reason' => null, 'errors' => [],
        ];
        if ($individual) {
            $parameters = $individual->parameters;
            // Taxation is exceptional; product identity remains owned by the product.
            // A null own GTIN denotes an untouched legacy rule and preserves compatibility.
            if ($product->fiscal_gtin !== null) {
                $parameters['gtin'] = $product->fiscal_gtin;
                $parameters['cest'] = $product->fiscal_cest;
            }
            $result = array_replace($result, ['parameters' => $parameters, 'accountant_reference' => $individual->accountant_reference, 'validated_at' => $individual->updated_at?->toIso8601String(), 'source' => 'individual']);
        } else {
            $profileId = $product->fiscal_tax_profile_id ?: $group?->fiscal_tax_profile_id;
            if (! $profileId) { return null; }
            $result = array_replace($result, ['profile_id' => (int) $profileId, 'source' => $product->fiscal_tax_profile_id ? 'product_profile' : 'group_profile', 'profile_revision' => $profile?->revision, 'profile_name' => $profile?->name, 'accountant_reference' => $profile?->accountant_reference, 'validated_at' => $profile?->validated_at?->toIso8601String()]);
            if (! $profile || ! $profile->active) {
                return $this->finish(array_replace($result, ['available' => false, 'unavailable_reason' => 'inactive_profile', 'parameters' => ($profile?->parameters ?? []) + ['gtin' => $product->fiscal_gtin, 'cest' => $product->fiscal_cest]]));
            }
            try { $result['parameters'] = app(TaxProfile::class)->validateShared($profile->parameters) + ['gtin' => $product->fiscal_gtin, 'cest' => $product->fiscal_cest]; }
            catch (ValidationException $e) { return $this->finish(array_replace($result, ['available' => false, 'unavailable_reason' => 'invalid_profile', 'errors' => $e->errors()])); }
        }
        try {
            $result['parameters'] = app(TaxProfile::class)->validate($result['parameters']);
            if (! $result['accountant_reference']) { $result['available'] = false; $result['unavailable_reason'] = 'missing_validation'; }
        } catch (ValidationException $e) {
            $result['available'] = false;
            $result['unavailable_reason'] = $result['source'] === 'individual' ? 'invalid_individual_rule' : 'invalid_product_identity';
            $result['errors'] = $e->errors();
        }
        return $this->finish($result);
    }

    private function finish(array $rule): array
    {
        $rule['unavailable_message'] = $rule['available'] ? null : $this->explain($rule);
        return $rule;
    }
}
