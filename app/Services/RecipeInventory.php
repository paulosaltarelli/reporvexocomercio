<?php

namespace App\Services;

use App\Models\{OperationRecord, Product, RestaurantModifierGroup, RestaurantProductConfig, Sale, SaleItem, StockMovement};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Recipe quantities are frozen on order entry; stock valuation is taken only on checkout. */
final class RecipeInventory
{
    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['recipe' => $message]);
    }

    /** Generic channels resolve base recipes on the server, never from request-supplied snapshots. */
    public function plansForProducts(array $ids): array
    {
        if (!$ids) return [];
        $configs = RestaurantProductConfig::whereIn('product_id', $ids)->where('stock_mode', 'recipe')->get();
        if ($configs->isEmpty()) return [];
        $required = RestaurantModifierGroup::whereIn('product_id', $configs->pluck('product_id'))->where('active', true)
            ->where('min_choices', '>', 0)->pluck('product_id')->all();
        return $configs->mapWithKeys(fn ($config) => [$config->product_id => ['mode' => 'recipe', 'revision' => $config->revision,
            'ingredients' => $config->recipe['ingredients'] ?? [], 'requires_choices' => in_array($config->product_id, $required, true)]])->all();
    }

    public function normalizeIngredients(int $dishId, array $rows, array $configuration): array
    {
        if (count($rows) > 50) $this->invalid('Use até 50 ingredientes por ficha.');
        if ($rows && !$configuration['inventory']) $this->invalid('Habilite a gestão de estoque antes de configurar ingredientes.');
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !ctype_digit((string)($row['product_id'] ?? '')) || (int)$row['product_id'] === $dishId) $this->invalid('Selecione ingredientes válidos, diferentes do produto preparado.');
            $ingredient = Product::where('active', true)->where('type', 'product')->sharedLock()->find($row['product_id']);
            if (!$ingredient || !$ingredient->controlsStock()) $this->invalid('Ingredientes precisam ser produtos ativos com controle de estoque.');
            if (RestaurantProductConfig::where('product_id', $ingredient->id)->where('stock_mode', 'recipe')->exists()) $this->invalid('Use ingredientes de estoque; fichas técnicas não podem conter outras fichas.');
            [$quantity, $factor] = MeasureUnits::convert($ingredient, $configuration, $row, 'sale');
            $result[] = ['product_id' => $ingredient->id, 'quantity' => $quantity, 'unit' => $ingredient->unit,
                'source_quantity' => Decimal::input((string)$row['quantity'], 3, 'recipe', '0.001', '999999'),
                'source_unit' => $row['measure_unit'] ?? $ingredient->unit, 'factor' => $factor];
        }
        return $result;
    }

    /** Aggregate repeated ingredients and reject quantities below the stock precision. */
    public function quantities(array $recipe, string $dishQuantity): array
    {
        $quantity = Decimal::input($dishQuantity, 3, 'quantity', '0.001', '999999');
        $result = [];
        foreach ($recipe['ingredients'] ?? [] as $row) {
            if (!is_array($row) || !ctype_digit((string)($row['product_id'] ?? '')) || !is_string($row['unit'] ?? null)) $this->invalid('A ficha registrada está incompleta. Revise o atendimento.');
            $perUnit = Decimal::input((string)($row['quantity'] ?? ''), 3, 'recipe', '0.001', '999999');
            $id = (int)$row['product_id'];
            if (isset($result[$id]) && $result[$id]['unit'] !== $row['unit']) $this->invalid('A ficha contém unidades incompatíveis para o mesmo ingrediente.');
            $result[$id] = ['product_id' => $id, 'unit' => $row['unit'],
                'quantity' => bcadd($result[$id]['quantity'] ?? '0', $perUnit, 3)];
        }
        foreach ($result as &$ingredient) {
            $exact = bcmul($ingredient['quantity'], $quantity, 6);
            $scaled = bcadd($exact, '0', 3);
            if (bccomp($exact, $scaled, 6) !== 0 || bccomp($scaled, '0', 3) <= 0) $this->invalid('A quantidade do pedido deve gerar ingredientes com até três casas decimais, sem arredondamento.');
            $ingredient['quantity'] = $scaled;
            Decimal::input($ingredient['quantity'], 3, 'recipe', '0.001', '999999999');
        }
        unset($ingredient);
        ksort($result);
        return array_values($result);
    }

    public function consume(Sale $sale, SaleItem $line, Product $dish, string $quantity, ?array $recipe, int $actor, array $configuration): array
    {
        if ($recipe === null) return ['handled_base' => false, 'cost' => '0.0000000'];
        $mode = $recipe['mode'] ?? 'product';
        if (!in_array($mode, ['product', 'recipe'], true)) $this->invalid('O modo da ficha registrada é inválido.');
        $ingredients = $this->quantities($recipe, $quantity);
        if ($mode === 'product' && !$ingredients) return ['handled_base' => false, 'cost' => '0.0000000'];
        if (!$configuration['inventory']) $this->invalid('A gestão de estoque está desabilitada. Reative-a antes de fechar pedidos com ingredientes.');
        if ($mode === 'recipe' && (!$ingredients || $dish->controlsStock() || bccomp((string)$dish->quantity, '0', 3) !== 0)) $this->invalid('O produto preparado deve usar somente ingredientes, sem saldo ou controle de estoque próprio.');
        $products = Product::whereIn('id', array_column($ingredients, 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $inventory = app(Inventory::class);
        $total = '0.0000000';
        $movements = [];
        foreach ($ingredients as $ingredient) {
            $product = $products->get($ingredient['product_id']);
            if (!$product || !$product->active || !$product->controlsStock() || $product->unit !== $ingredient['unit']) $this->invalid('Um ingrediente foi desativado ou teve seu controle ou unidade alterados. Revise a ficha registrada.');
            $key = 'recipe-sale-'.$line->id.'-'.$product->id;
            $inventory->move($product->id, 'sale', $ingredient['quantity'], (string)$product->average_cost,
                'Ingredientes da venda #'.$sale->id, $actor, $key, $sale->id);
            $movement = StockMovement::where('request_key', $key)->firstOrFail();
            $value = $inventory->movementValue($key);
            if ($value === null) $this->invalid('Não foi possível conferir a valorização do ingrediente.');
            $total = bcadd($total, $value, 7);
            $movements[] = $ingredient + ['movement_id' => $movement->id, 'unit_cost' => (string)$movement->unit_cost, 'movement_value' => $value];
        }
        OperationRecord::create(['action' => 'restaurant_recipe_consumed', 'version' => 1, 'user_id' => $actor,
            'before' => [], 'after' => ['sale_id' => $sale->id, 'sale_item_id' => $line->id, 'product_id' => $dish->id,
                'recipe_revision' => (int)($recipe['revision'] ?? 0), 'mode' => $mode, 'base_unit_cost' => (string)$line->unit_cost,
                'ingredients' => $movements], 'created_at' => now()]);
        return ['handled_base' => $mode === 'recipe', 'cost' => $total, 'has_ingredients' => true];
    }

    /** Return exactly the recorded ingredients and valuation, never today's recipe or unit links. */
    public function cancel(Sale $sale, int $actor): void
    {
        $records = OperationRecord::where('action', 'restaurant_recipe_consumed')->where('after->sale_id', $sale->id)->orderBy('id')->get();
        $ids = $records->flatMap(fn ($record) => array_column($record->after['ingredients'], 'product_id'))->unique()->sort()->values()->all();
        $products = Product::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($records as $record) {
            foreach ($record->after['ingredients'] as $ingredient) {
                $product = $products->get($ingredient['product_id']);
                if (!$product || !$product->controlsStock() || $product->unit !== $ingredient['unit']) $this->invalid('Restaure a unidade e o controle de estoque do ingrediente antes de estornar esta venda.');
                app(Inventory::class)->move($product->id, 'return', $ingredient['quantity'], $ingredient['unit_cost'],
                    'Estorno ingredientes venda #'.$sale->id, $actor, 'recipe-cancel-'.$record->after['sale_item_id'].'-'.$product->id,
                    $sale->id, $ingredient['movement_value']);
            }
        }
    }
}
