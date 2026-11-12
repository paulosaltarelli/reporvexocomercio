<?php

namespace App\Fiscal;

use App\Models\FiscalItemRule;
use App\Models\FiscalTaxProfile;
use App\Models\FiscalTaxProfileAudit;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class FiscalProfileManager
{
    public function authorize(Tenant $tenant, int $userId): void
    {
        abort_unless($tenant->active && DB::connection('tenant')->getDatabaseName() === $tenant->db_name, 403);
        abort_unless(User::whereKey($userId)->where('active', true)->where('role', 'admin')->exists(), 403);
    }

    public function save(Tenant $tenant, int $userId, array $data, ?int $id = null, ?int $expectedRevision = null): FiscalTaxProfile
    {
        $this->authorize($tenant, $userId);
        $valid = Validator::make($data, ['name' => 'required|string|max:120', 'active' => 'required|boolean', 'accountant_reference' => 'required|string|min:3|max:180', 'parameters' => 'required|array'])->validate();
        $valid['name'] = trim($valid['name']);
        $valid['accountant_reference'] = trim($valid['accountant_reference']);
        if ($valid['name'] === '' || mb_strlen($valid['accountant_reference']) < 3) { $this->invalid('name', 'Informe nome e referência contábil válidos.'); }
        $valid['parameters'] = app(TaxProfile::class)->validateShared($valid['parameters']);
        try {
            return DB::connection('tenant')->transaction(function () use ($userId, $valid, $id, $expectedRevision) {
                $profile = $id ? FiscalTaxProfile::whereKey($id)->lockForUpdate()->firstOrFail() : new FiscalTaxProfile;
                if ($id && ($expectedRevision === null || $profile->revision !== $expectedRevision)) { $this->invalid('revision', 'O perfil foi alterado. Reabra e revise antes de salvar.'); }
                $before = $id ? $this->profileState($profile) : null;
                $profile->fill($valid);
                $profile->revision = $id ? $expectedRevision + 1 : 1;
                $profile->validated_by = $userId;
                $profile->validated_at = now();
                $profile->save();
                $this->audit($userId, $profile->id, $id ? 'profile_update' : 'profile_create', null, null, $before, $this->profileState($profile));
                return $profile;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $this->invalid('name', 'Já existe um perfil com esse nome.');
        }
    }

    public function preview(Tenant $tenant, int $userId, int $profileId, array $productIds, bool $replaceExisting = false): array
    {
        $this->authorize($tenant, $userId);
        $ids = $this->ids($productIds);
        return $this->capture($tenant, $profileId, $ids, $replaceExisting, false);
    }

    public function apply(Tenant $tenant, int $userId, int $profileId, array $productIds, string $expectedHash, string $requestKey, bool $replaceExisting = false): array
    {
        $this->authorize($tenant, $userId);
        $ids = $this->ids($productIds);
        $this->request($requestKey, $expectedHash);
        $payload = Snapshot::hash(['operation' => 'bulk_apply', 'profile_id' => $profileId, 'ids' => $ids, 'expected_hash' => $expectedHash, 'replace_existing' => $replaceExisting, 'actor_id' => $userId]);
        return $this->idempotent($requestKey, $payload, function () use ($tenant, $userId, $profileId, $ids, $replaceExisting, $expectedHash, $requestKey, $payload) {
            $preview = $this->capture($tenant, $profileId, $ids, $replaceExisting, true);
            if (! hash_equals($preview['hash'], $expectedHash)) { $this->invalid('expected_hash', 'O catálogo ou perfil mudou. Gere uma nova prévia antes de aplicar.'); }
            $appliedIds = [];
            foreach ($preview['rows'] as $row) {
                if ($row['status'] !== 'apply') { continue; }
                DB::connection('tenant')->table('products')->where('id', $row['id'])->update(['fiscal_tax_profile_id' => $profileId, 'updated_at' => now()]);
                $appliedIds[] = $row['id'];
            }
            $summary = ['applied' => count($appliedIds), 'skipped' => $preview['skipped'], 'product_ids' => $appliedIds, 'profile_id' => $profileId, 'profile_revision' => $preview['profile']->revision];
            $this->audit($userId, $profileId, 'bulk_apply', $requestKey, $payload, ['rows' => $preview['rows']], ['profile_id' => $profileId, 'product_ids' => $appliedIds], $summary);
            return $summary;
        });
    }

    /** Hash only fiscal identity/binding state, not stock or commercial balances. */
    public function bindingHash(Product $product): string
    {
        $group = $product->product_group_id ? ProductGroup::find($product->product_group_id) : null;
        $rule = FiscalItemRule::where('product_id', $product->id)->first();
        $profiles = FiscalTaxProfile::whereIn('id', array_filter([$product->fiscal_tax_profile_id, $group?->fiscal_tax_profile_id]))->orderBy('id')->get()->map(fn ($p) => $this->profileState($p))->all();
        return Snapshot::hash(['product' => $this->productState($product), 'group' => $group?->only(['id', 'fiscal_tax_profile_id']), 'individual' => $rule?->only(['id', 'parameters', 'accountant_reference', 'updated_at']), 'profiles' => $profiles]);
    }

    public function groupBindingHash(ProductGroup $group): string
    {
        $profile = $group->fiscal_tax_profile_id ? FiscalTaxProfile::find($group->fiscal_tax_profile_id) : null;
        return Snapshot::hash(['group' => $group->only(['id', 'fiscal_tax_profile_id']), 'profile' => $profile ? $this->profileState($profile) : null]);
    }

    public function individualHash(Product $product): ?string
    {
        $rule = FiscalItemRule::where('product_id', $product->id)->first();
        return $rule ? Snapshot::hash(['binding' => $this->bindingHash($product), 'individual' => $rule->only(['id', 'parameters', 'accountant_reference', 'updated_at'])]) : null;
    }

    public function bindProduct(Tenant $tenant, int $userId, int $productId, ?int $profileId, ?string $gtin, ?string $cest, string $expectedHash, string $requestKey): Product
    {
        $this->authorize($tenant, $userId);
        $this->request($requestKey, $expectedHash);
        $identity = Validator::make(['gtin' => $gtin, 'cest' => $cest], ['gtin' => ['nullable', 'regex:/^(?:SEM GTIN|[0-9]{8}|[0-9]{12,14})$/'], 'cest' => 'nullable|digits:7'])->validate();
        // Reuse the full GTIN DV validator without creating or defaulting product identity.
        if ($gtin !== null && $gtin !== '') {
            $this->validateIdentity($gtin, $cest);
        }
        $payload = Snapshot::hash(['operation' => 'product_bind', 'product_id' => $productId, 'profile_id' => $profileId, 'gtin' => $gtin, 'cest' => $cest, 'expected_hash' => $expectedHash, 'actor_id' => $userId]);
        $this->idempotent($requestKey, $payload, function () use ($userId, $productId, $profileId, $gtin, $cest, $expectedHash, $requestKey, $payload) {
            $product = Product::whereKey($productId)->lockForUpdate()->firstOrFail();
            if ($product->type !== 'product') { $this->invalid('product', 'Perfil de mercadoria não se aplica a serviço.'); }
            $this->lockBindings($product);
            if (($gtin === null || $gtin === '') && FiscalItemRule::where('product_id', $productId)->sharedLock()->first()) {
                $this->invalid('fiscal_gtin', 'Informe o GTIN do produto ou SEM GTIN explicitamente. Uma exceção individual exige identidade fiscal válida.');
            }
            if (! hash_equals($this->bindingHash($product), $expectedHash)) { $this->invalid('expected_hash', 'A configuração do produto mudou. Reabra o cadastro.'); }
            if ($profileId) { $this->activeProfile($profileId, true); }
            $before = $this->productState($product);
            $product->update(['fiscal_tax_profile_id' => $profileId, 'fiscal_gtin' => $gtin ?: null, 'fiscal_cest' => $cest ?: null]);
            $this->audit($userId, $profileId, 'product_bind', $requestKey, $payload, $before, $this->productState($product), ['product_id' => $productId]);
            return ['product_id' => $productId];
        });
        return Product::findOrFail($productId);
    }

    public function assignGroup(Tenant $tenant, int $userId, int $groupId, ?int $profileId, string $expectedHash, string $requestKey): ProductGroup
    {
        $this->authorize($tenant, $userId);
        $this->request($requestKey, $expectedHash);
        $payload = Snapshot::hash(['operation' => 'group_bind', 'group_id' => $groupId, 'profile_id' => $profileId, 'expected_hash' => $expectedHash, 'actor_id' => $userId]);
        $this->idempotent($requestKey, $payload, function () use ($userId, $groupId, $profileId, $expectedHash, $requestKey, $payload) {
            $group = ProductGroup::whereKey($groupId)->lockForUpdate()->firstOrFail();
            if ($group->fiscal_tax_profile_id) { FiscalTaxProfile::whereKey($group->fiscal_tax_profile_id)->sharedLock()->first(); }
            if ($profileId) { $this->activeProfile($profileId, true); }
            if (! hash_equals($this->groupBindingHash($group), $expectedHash)) { $this->invalid('expected_hash', 'O padrão do grupo mudou. Reabra a configuração.'); }
            $before = $group->only(['id', 'fiscal_tax_profile_id']);
            $group->update(['fiscal_tax_profile_id' => $profileId]);
            $this->audit($userId, $profileId, 'group_bind', $requestKey, $payload, $before, $group->only(['id', 'fiscal_tax_profile_id']), ['group_id' => $groupId]);
            return ['group_id' => $groupId];
        });
        return ProductGroup::findOrFail($groupId);
    }

    public function clearIndividual(Tenant $tenant, int $userId, int $productId, string $expectedHash, string $requestKey): Product
    {
        $this->authorize($tenant, $userId);
        $this->request($requestKey, $expectedHash);
        $payload = Snapshot::hash(['operation' => 'individual_clear', 'product_id' => $productId, 'expected_hash' => $expectedHash, 'actor_id' => $userId]);
        $this->idempotent($requestKey, $payload, function () use ($userId, $productId, $expectedHash, $requestKey, $payload) {
            $product = Product::whereKey($productId)->lockForUpdate()->firstOrFail();
            $rule = FiscalItemRule::where('product_id', $productId)->lockForUpdate()->first();
            $this->lockBindings($product);
            if (! $rule || ! hash_equals((string) $this->individualHash($product), $expectedHash)) { $this->invalid('expected_hash', 'A exceção mudou. Reabra e revise antes de removê-la.'); }
            $before = $rule->only(['id', 'product_id', 'parameters', 'accountant_reference', 'updated_at']);
            $rule->delete();
            $fallback = app(FiscalRuleResolver::class)->resolve($product, true);
            if (! $fallback || ! $fallback['available'] || $fallback['source'] === 'individual') { $this->invalid('profile_id', 'Associe um perfil ativo e complete GTIN/CEST do produto antes de retirar a exceção.'); }
            $this->audit($userId, $fallback['profile_id'], 'individual_clear', $requestKey, $payload, $before, $fallback, ['product_id' => $productId]);
            return ['product_id' => $productId];
        });
        return Product::findOrFail($productId);
    }

    private function capture(Tenant $tenant, int $profileId, array $ids, bool $replaceExisting, bool $lock): array
    {
        $query = Product::whereIn('id', $ids)->orderBy('id');
        $products = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($products->count() !== count($ids)) { $this->invalid('product_ids', 'Há produtos que não pertencem ao catálogo atual.'); }
        $query = FiscalItemRule::whereIn('product_id', $ids)->orderBy('product_id');
        $rules = ($lock ? $query->sharedLock() : $query)->get()->keyBy('product_id');
        $query = ProductGroup::whereIn('id', $products->pluck('product_group_id')->filter()->unique())->orderBy('id');
        $groups = ($lock ? $query->sharedLock() : $query)->get()->keyBy('id');
        $profile = $this->activeProfile($profileId, $lock);
        $rows = [];
        foreach ($products as $product) {
            $reason = null;
            if ($product->type !== 'product') { $reason = 'Serviço: perfil de mercadoria não se aplica.'; }
            elseif ($rules->has($product->id)) { $reason = 'Exceção individual preservada.'; }
            elseif ((int) $product->fiscal_tax_profile_id === $profileId) { $reason = 'Este perfil já está associado diretamente.'; }
            elseif ($product->fiscal_tax_profile_id && ! $replaceExisting) { $reason = 'Perfil direto existente preservado; substituição não foi confirmada.'; }
            $needsIdentity = false;
            try { $this->validateIdentity($product->fiscal_gtin, $product->fiscal_cest); }
            catch (ValidationException) { $needsIdentity = true; }
            $rows[] = ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'status' => $reason ? 'skip' : 'apply', 'reason' => $reason ?? ($needsIdentity ? 'Associar perfil; complete o GTIN próprio antes de emitir.' : 'Associar perfil selecionado.'), 'needs_identity' => $needsIdentity, 'from_profile_id' => $product->fiscal_tax_profile_id, 'group_id' => $product->product_group_id];
        }
        $hash = Snapshot::hash([
            'tenant_id' => $tenant->id, 'target_profile' => $this->profileState($profile), 'replace_existing' => $replaceExisting,
            'products' => $products->map(fn ($p) => $this->productState($p))->values()->all(),
            'groups' => $groups->map(fn ($g) => $g->only(['id', 'fiscal_tax_profile_id']))->values()->all(),
            'individuals' => $rules->map(fn ($r) => $r->only(['id', 'product_id', 'parameters', 'accountant_reference', 'updated_at']))->values()->all(),
        ]);
        $applied = count(array_filter($rows, fn ($row) => $row['status'] === 'apply'));
        return ['profile' => $profile, 'rows' => $rows, 'hash' => $hash, 'applied' => $applied, 'skipped' => count($rows) - $applied, 'product_ids' => $ids, 'replace_existing' => $replaceExisting];
    }

    private function activeProfile(int $id, bool $lock): FiscalTaxProfile
    {
        $query = FiscalTaxProfile::whereKey($id);
        $profile = ($lock ? $query->sharedLock() : $query)->firstOrFail();
        if (! $profile->active) { $this->invalid('profile_id', 'O perfil selecionado está inativo.'); }
        app(TaxProfile::class)->validateShared($profile->parameters);
        if (! $profile->accountant_reference || ! $profile->validated_at) { $this->invalid('profile_id', 'O perfil precisa de referência e validação contábil.'); }
        return $profile;
    }

    private function lockBindings(Product $product): void
    {
        FiscalItemRule::where('product_id', $product->id)->sharedLock()->first();
        $group = $product->product_group_id ? ProductGroup::whereKey($product->product_group_id)->sharedLock()->first() : null;
        FiscalTaxProfile::whereIn('id', array_filter([$product->fiscal_tax_profile_id, $group?->fiscal_tax_profile_id]))->orderBy('id')->sharedLock()->get();
    }

    private function validateIdentity(?string $gtin, ?string $cest): void
    {
        Validator::make(['gtin' => $gtin, 'cest' => $cest], ['gtin' => ['required', 'regex:/^(?:SEM GTIN|[0-9]{8}|[0-9]{12,14})$/'], 'cest' => 'nullable|digits:7'])->validate();
        if ($gtin === 'SEM GTIN') { return; }
        $sum = 0;
        $weight = 3;
        for ($i = strlen($gtin) - 2; $i >= 0; $i--) { $sum += (int) $gtin[$i] * $weight; $weight = $weight === 3 ? 1 : 3; }
        if (preg_match('/^0+$/D', $gtin) || (int) substr($gtin, -1) !== (10 - $sum % 10) % 10) { $this->invalid('gtin', 'Dígito verificador do GTIN inválido.'); }
    }

    private function profileState(FiscalTaxProfile $profile): array
    {
        return $profile->only(['id', 'name', 'active', 'revision', 'parameters', 'accountant_reference', 'validated_by', 'validated_at']);
    }

    private function productState(Product $product): array
    {
        return $product->only(['id', 'name', 'sku', 'type', 'product_group_id', 'fiscal_tax_profile_id', 'fiscal_gtin', 'fiscal_cest', 'ncm', 'origin', 'unit']);
    }

    private function ids(array $ids): array
    {
        Validator::make(['product_ids' => $ids], ['product_ids' => 'required|array|min:1|max:200', 'product_ids.*' => 'required|integer|min:1'])->validate();
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function request(string $key, string $hash): void
    {
        Validator::make(['request_key' => $key, 'expected_hash' => $hash], ['request_key' => 'required|uuid', 'expected_hash' => 'required|regex:/^[a-f0-9]{64}$/D'])->validate();
    }

    private function idempotent(string $key, string $payload, callable $action): array
    {
        try {
            return DB::connection('tenant')->transaction(function () use ($key, $payload, $action) {
                $existing = FiscalTaxProfileAudit::where('request_key', $key)->lockForUpdate()->first();
                if ($existing) {
                    if (! hash_equals((string) $existing->payload_sha256, $payload)) { $this->invalid('request_key', 'Chave já utilizada para outra alteração fiscal.'); }
                    return $existing->summary ?? [];
                }
                return $action();
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            $existing = FiscalTaxProfileAudit::where('request_key', $key)->first();
            if ($existing) {
                if (! hash_equals((string) $existing->payload_sha256, $payload)) { $this->invalid('request_key', 'Chave já utilizada para outra alteração fiscal.'); }
                return $existing->summary ?? [];
            }
            throw $e;
        }
    }

    private function audit(int $userId, ?int $profileId, string $operation, ?string $key, ?string $payload, ?array $before, ?array $after, ?array $summary = null): void
    {
        FiscalTaxProfileAudit::create(['actor_id' => $userId, 'profile_id' => $profileId, 'operation' => $operation, 'request_key' => $key, 'payload_sha256' => $payload, 'before_state' => $before, 'after_state' => $after, 'summary' => $summary]);
    }

    private function invalid(string $field, string $message): never { throw ValidationException::withMessages([$field => $message]); }
}
