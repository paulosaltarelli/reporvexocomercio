<?php

namespace App\Services;

use App\Models\OperationRecord;
use App\Models\SupermarketCart;
use App\Models\SupermarketDraftImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Reconciles offline item drafts online. It never records payments or completes a sale. */
final class SupermarketDrafts
{
    public function __construct(private Supermarket $market) {}

    public function validate(array $input): array
    {
        $data = Validator::make($input, ['request_key' => 'required|uuid', 'version' => 'required|integer|min:0',
            'items' => 'required|array|min:1|max:200', 'items.*.request_key' => 'required|uuid|distinct',
            'items.*.code' => 'required|string|max:80', 'items.*.quantity' => 'required|string|max:24'])->validate();
        $data['items'] = array_map(fn ($line) => ['request_key' => $line['request_key'], 'code' => trim($line['code']),
            'quantity' => Decimal::input($line['quantity'], 3, 'items', '0.001', '999999999.999')], $data['items']);
        return $data;
    }

    public function preview(int $cartId, array $input, int $userId): array
    {
        $data = $this->validate($input);
        return DB::connection('tenant')->transaction(function () use ($cartId, $data, $userId) {
            $this->market->actor($userId);
            $cart = $this->market->owned($cartId, $userId, true);
            $this->editable($cart, (int) $data['version']);
            $result = [];
            foreach ($data['items'] as $line) {
                $scan = $this->market->scan($line['code']);
                if ($scan['label'] !== null && bccomp($line['quantity'], '1', 3) !== 0) {
                    throw ValidationException::withMessages(['items' => 'Cada etiqueta pesável deve ser lida com quantidade 1.']);
                }
                $product = $scan['product'];
                $quantity = $scan['quantity'] ?? $line['quantity'];
                $terms = app(CommercialRules::class)->quote($product, ['quantity' => $quantity, 'unit_price' => $product->sale_price]);
                $result[] = ['request_key' => $line['request_key'], 'description' => $product->name, 'quantity' => $terms['quantity'],
                    'unit' => $product->unit, 'unit_price' => (string) $product->sale_price, 'total' => $terms['total']];
            }
            return ['cart_id' => $cart->id, 'version' => $cart->version, 'items' => $result];
        }, 3);
    }

    public function import(int $cartId, array $input, int $userId): array
    {
        $data = $this->validate($input);
        return DB::connection('tenant')->transaction(function () use ($cartId, $data, $userId) {
            $this->market->actor($userId);
            $cart = $this->market->owned($cartId, $userId, true);
            $keys = array_column($data['items'], 'request_key');
            $existing = SupermarketDraftImport::whereIn('request_key', $keys)->orderBy('id')->lockForUpdate()->get()->keyBy('request_key');
            foreach ($data['items'] as $line) {
                $record = $existing->get($line['request_key']);
                if ($record && ((int) $record->user_id !== $userId || (int) $record->cart_id !== $cartId
                    || !hash_equals($record->payload_hash, $this->fingerprint($cartId, $userId, $line)))) {
                    throw ValidationException::withMessages(['items' => 'Um item do rascunho já pertence a outro lançamento. Revise a fila antes de continuar.']);
                }
            }
            $pending = array_values(array_filter($data['items'], fn ($line) => !$existing->has($line['request_key'])));
            if ($pending) $this->editable($cart, (int) $data['version']);
            foreach ($pending as $line) {
                $cart = $this->market->add($cart->id, $cart->version, $line['code'], $line['quantity'], $userId);
                SupermarketDraftImport::create(['request_key' => $line['request_key'], 'cart_id' => $cart->id,
                    'user_id' => $userId, 'payload' => ['code' => $line['code'], 'quantity' => $line['quantity']],
                    'payload_hash' => $this->fingerprint($cartId, $userId, $line),
                    'line_key' => $cart->lastScannedLineKey, 'cart_version' => $cart->version, 'created_at' => now()]);
            }
            if ($pending) OperationRecord::create(['action' => 'market_draft_import', 'version' => 1, 'user_id' => $userId, 'before' => [],
                'after' => ['cart_id' => $cartId, 'request_keys' => array_column($pending, 'request_key'), 'batch_key' => $data['request_key']], 'created_at' => now()]);
            return ['ok' => true, 'cart_id' => $cart->id, 'version' => $cart->version, 'imported_request_keys' => $keys];
        }, 3);
    }

    private function fingerprint(int $cartId, int $userId, array $line): string
    {
        return hash('sha256', json_encode([$cartId, $userId, $line['code'], $line['quantity']], JSON_THROW_ON_ERROR));
    }

    private function editable(SupermarketCart $cart, int $version): void
    {
        app(SupermarketShifts::class)->forCart($cart);
        if ($cart->status !== 'active' || $cart->version !== $version) {
            throw ValidationException::withMessages(['version' => 'O atendimento mudou. Confira a venda online antes de importar o rascunho.']);
        }
    }
}
