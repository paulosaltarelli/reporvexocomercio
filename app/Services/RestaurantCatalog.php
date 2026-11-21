<?php

namespace App\Services;

use App\Models\{OperationRecord, Product, RestaurantModifier, RestaurantModifierGroup, RestaurantProductConfig};
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;

final class RestaurantCatalog
{
    public function menuQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Product::where('active', true)->where('type', 'product')->whereNotIn('id',
            RestaurantProductConfig::where('show_on_menu', false)->select('product_id'));
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function publicCatalog(iterable $products): array
    {
        $ids = collect($products)->map(fn ($product) => $product->id)->all();
        $configs = RestaurantProductConfig::whereIn('product_id', $ids)->get()->keyBy('product_id');
        $groups = RestaurantModifierGroup::whereIn('product_id', $ids)->where('active', true)
            ->with(['options' => fn ($query) => $query->where('active', true)->orderBy('id')])->orderBy('id')->get()->groupBy('product_id');
        $result = [];
        foreach ($ids as $id) {
            $config = $configs->get($id);
            if ($config && !$config->show_on_menu) continue;
            $result[$id] = ['station' => $config?->station ?? 'kitchen', 'stock_mode' => $config?->stock_mode ?? 'product',
                'show_on_menu' => true,
                'revision' => $config?->revision ?? 0, 'groups' => ($groups->get($id) ?? collect())->map(fn ($group) => [
                    'id' => $group->id, 'name' => $group->name, 'min_choices' => $group->min_choices, 'max_choices' => $group->max_choices,
                    'options' => $group->options->map(fn ($option) => ['id' => $option->id, 'name' => $option->name, 'price' => $option->price])->values()->all(),
                ])->values()->all()];
        }
        return $result;
    }

    public function quote(Product $product, array $optionIds, string $quantity): array
    {
        $valid = Validator::make(['modifier_ids' => $optionIds], ['modifier_ids' => 'array|max:50', 'modifier_ids.*' => 'required|integer|min:1|distinct'])->validate();
        $ids = array_map('intval', $valid['modifier_ids']);
        $config = RestaurantProductConfig::where('product_id', $product->id)->sharedLock()->first();
        if ($config && !$config->show_on_menu) $this->invalid('product_id', 'Este produto está oculto no cardápio.');
        $groups = RestaurantModifierGroup::where('product_id', $product->id)->where('active', true)->with(['options' => fn ($query) => $query->where('active', true)])->orderBy('id')->get();
        $available = $groups->flatMap->options->keyBy('id');
        if (array_diff($ids, $available->keys()->all())) $this->invalid('modifier_ids', 'Escolha adicionais disponíveis para este produto.');
        $addons = [];
        $price = Decimal::input((string)$product->sale_price, 2, 'items');
        $recipe = ['mode' => $config?->stock_mode ?? 'product', 'revision' => $config?->revision ?? 0,
            'ingredients' => $config?->stock_mode === 'recipe' ? ($config->recipe['ingredients'] ?? []) : []];
        foreach ($groups as $group) {
            $selected = $group->options->whereIn('id', $ids);
            if ($selected->count() < $group->min_choices || $selected->count() > $group->max_choices) $this->invalid('modifier_ids', 'Confira a quantidade de escolhas no grupo '.$group->name.'.');
            foreach ($selected as $option) {
                $price = bcadd($price, $option->price, 2);
                $addons[] = ['id' => $option->id, 'group_id' => $group->id, 'group_name' => $group->name, 'name' => $option->name, 'price' => (string)$option->price];
                if ($option->ingredient_product_id) $recipe['ingredients'][] = ['product_id' => $option->ingredient_product_id,
                    'quantity' => (string)$option->ingredient_quantity, 'unit' => $option->ingredient_unit];
            }
        }
        Decimal::input($price, 2, 'items');
        if ($recipe['mode'] === 'recipe' || $recipe['ingredients']) {
            if (!app(CompanyOperations::class)->current()['inventory']) $this->invalid('recipe', 'Habilite a gestão de estoque para lançar itens com ingredientes.');
            if ($recipe['mode'] === 'recipe' && ($product->controlsStock() || bccomp((string)$product->quantity, '0', 3) !== 0 || !$recipe['ingredients'])) $this->invalid('recipe', 'Revise a ficha: o produto preparado deve usar ingredientes e não controlar estoque próprio.');
            app(RecipeInventory::class)->quantities($recipe, $quantity);
        }
        return ['station' => $config?->station ?? 'kitchen', 'unit_price' => $price, 'description' => $product->name, 'addons' => $addons, 'recipe' => $recipe];
    }

    public function save(int $productId, array $input, int $actor): RestaurantProductConfig
    {
        $data = Validator::make($input, [
            'version' => 'required|integer|min:0', 'station' => 'required|in:kitchen,bar', 'stock_mode' => 'required|in:product,recipe',
            'show_on_menu' => 'sometimes|required|boolean',
            'recipe' => 'sometimes|array', 'recipe.ingredients' => 'sometimes|array|max:50',
            'recipe.ingredients.*.product_id' => 'required|integer|min:1',
            'recipe.ingredients.*.quantity' => 'required|numeric|min:0.001|max:999999|decimal:0,3',
            'recipe.ingredients.*.measure_unit' => 'nullable|string|max:10', 'recipe.ingredients.*.factor_seen' => 'nullable|string|max:24',
            'groups' => 'sometimes|array|max:12', 'groups.*.id' => 'nullable|integer|min:1|distinct',
            'groups.*.name' => 'required|string|max:100', 'groups.*.active' => 'required|boolean',
            'groups.*.min_choices' => 'required|integer|min:0|max:20', 'groups.*.max_choices' => 'required|integer|min:1|max:20',
            'groups.*.options' => 'present|array|max:30', 'groups.*.options.*.id' => 'nullable|integer|min:1|distinct',
            'groups.*.options.*.name' => 'required|string|max:100', 'groups.*.options.*.price' => 'required|numeric|min:0|max:999999999.99|decimal:0,2',
            'groups.*.options.*.active' => 'required|boolean', 'groups.*.options.*.ingredient_product_id' => 'nullable|integer|min:1',
            'groups.*.options.*.ingredient_quantity' => 'nullable|numeric|min:0.001|max:999999|decimal:0,3',
            'groups.*.options.*.ingredient_measure_unit' => 'nullable|string|max:10', 'groups.*.options.*.ingredient_factor_seen' => 'nullable|string|max:24',
        ])->validate();
        return DB::connection('tenant')->transaction(function () use ($productId, $data, $actor) {
            $configuration = app(CompanyOperations::class)->lock();
            app(OperationalModes::class)->require('restaurant');
            InstallerAccess::authorizedActor($actor, 'restaurant.catalog.manage');
            $product = Product::where('active', true)->where('type', 'product')->lockForUpdate()->findOrFail($productId);
            $config = RestaurantProductConfig::where('product_id', $productId)->lockForUpdate()->first();
            if (($config?->revision ?? 0) !== (int)$data['version']) $this->invalid('version', 'O cardápio foi alterado. Reabra antes de salvar.');
            $ingredients = app(RecipeInventory::class)->normalizeIngredients($productId, $data['recipe']['ingredients'] ?? [], $configuration);
            if ($data['stock_mode'] === 'recipe' && (!$ingredients || $product->controlsStock() || bccomp((string)$product->quantity, '0', 3) !== 0)) $this->invalid('recipe', 'Cadastre ingredientes e deixe o produto preparado sem controle ou saldo de estoque próprio.');
            $before = ($config?->only(['station', 'stock_mode', 'show_on_menu', 'recipe', 'revision']) ?? []) + ['groups' => $this->groupSnapshot($productId)];
            $config ??= new RestaurantProductConfig(['product_id' => $productId]);
            $config->fill(['station' => $data['station'], 'stock_mode' => $data['stock_mode'], 'show_on_menu' => $data['show_on_menu'] ?? $config->show_on_menu ?? true, 'recipe' => ['ingredients' => $ingredients],
                'revision' => (int)$data['version'] + 1, 'updated_by' => $actor])->save();
            if (isset($data['groups'])) $this->saveGroups($productId, $data['groups'], $configuration);
            OperationRecord::create(['action' => 'restaurant_catalog_saved', 'version' => $config->revision, 'user_id' => $actor,
                'before' => $before, 'after' => ['product_id' => $productId, 'groups' => $this->groupSnapshot($productId)] + $config->only(['station', 'stock_mode', 'show_on_menu', 'recipe', 'revision']), 'created_at' => now()]);
            return $config;
        }, 3);
    }

    private function groupSnapshot(int $productId): array
    {
        return RestaurantModifierGroup::where('product_id', $productId)->with('options')->orderBy('id')->get()->map(fn ($group) =>
            $group->only(['id', 'name', 'min_choices', 'max_choices', 'active']) + ['options' => $group->options->map(fn ($option) =>
                $option->only(['id', 'name', 'price', 'active', 'ingredient_product_id', 'ingredient_quantity', 'ingredient_unit']))->values()->all()]
        )->values()->all();
    }

    private function saveGroups(int $productId, array $groups, array $configuration): void
    {
        $seenNames = [];
        $seenGroups = [];
        foreach ($groups as $input) {
            $name = trim($input['name']);
            if ($name === '' || isset($seenNames[mb_strtolower($name)])) $this->invalid('groups', 'Use nomes de grupos preenchidos e diferentes.');
            $seenNames[mb_strtolower($name)] = true;
            if ((int)$input['min_choices'] > (int)$input['max_choices']) $this->invalid('groups', 'O mínimo de escolhas não pode ultrapassar o máximo.');
            $group = empty($input['id']) ? new RestaurantModifierGroup(['product_id' => $productId])
                : RestaurantModifierGroup::where('product_id', $productId)->lockForUpdate()->findOrFail($input['id']);
            $group->fill(['name' => $name, 'min_choices' => $input['min_choices'], 'max_choices' => $input['max_choices'], 'active' => $input['active']])->save();
            $seenGroups[] = $group->id;
            $active = 0;
            $optionNames = [];
            $seenOptions = [];
            foreach ($input['options'] as $optionInput) {
                $optionName = trim($optionInput['name']);
                if ($optionName === '' || isset($optionNames[mb_strtolower($optionName)])) $this->invalid('groups', 'Use nomes de adicionais preenchidos e diferentes no grupo.');
                $optionNames[mb_strtolower($optionName)] = true;
                $option = empty($optionInput['id']) ? new RestaurantModifier(['restaurant_modifier_group_id' => $group->id])
                    : RestaurantModifier::where('restaurant_modifier_group_id', $group->id)->lockForUpdate()->findOrFail($optionInput['id']);
                $ingredient = null;
                if (!empty($optionInput['ingredient_product_id'])) {
                    $row = ['product_id' => $optionInput['ingredient_product_id'], 'quantity' => $optionInput['ingredient_quantity'] ?? ''];
                    if (!empty($optionInput['ingredient_measure_unit'])) $row += ['measure_unit' => $optionInput['ingredient_measure_unit'], 'factor_seen' => $optionInput['ingredient_factor_seen'] ?? ''];
                    $ingredient = app(RecipeInventory::class)->normalizeIngredients($productId, [$row], $configuration)[0];
                }
                $option->fill(['name' => $optionName, 'price' => Decimal::input((string)$optionInput['price'], 2, 'groups'), 'active' => $optionInput['active'],
                    'ingredient_product_id' => $ingredient['product_id'] ?? null, 'ingredient_quantity' => $ingredient['quantity'] ?? null,
                    'ingredient_unit' => $ingredient['unit'] ?? null])->save();
                $seenOptions[] = $option->id;
                if ($option->active) $active++;
            }
            RestaurantModifier::where('restaurant_modifier_group_id', $group->id)->whereNotIn('id', $seenOptions)->update(['active' => false]);
            if ($group->active && $active < $group->min_choices) $this->invalid('groups', 'Um grupo obrigatório precisa ter opções ativas suficientes para o mínimo de escolhas.');
        }
        RestaurantModifierGroup::where('product_id', $productId)->whereNotIn('id', $seenGroups)->update(['active' => false]);
    }
}
