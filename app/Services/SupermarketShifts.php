<?php

namespace App\Services;

use App\Models\OperationRecord;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SupermarketCart;
use App\Models\SupermarketShift;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SupermarketShifts
{
    public function current(int $userId): ?SupermarketShift
    {
        return SupermarketShift::where('user_id', $userId)->where('open_slot', true)->first();
    }

    public function open(array $data, int $userId): SupermarketShift
    {
        $data = Validator::make($data, [
            'request_key' => 'required|uuid',
            'terminal_number' => 'required|integer|between:1,999',
            'opening_balance' => 'required|numeric',
        ])->validate();
        $terminal = (int) $data['terminal_number'];
        $opening = Decimal::input((string) $data['opening_balance'], 2, 'opening_balance');
        try {
            return DB::connection('tenant')->transaction(function () use ($data, $userId, $terminal, $opening) {
                app(Supermarket::class)->actor($userId);
                $existing = SupermarketShift::where('open_request_key', $data['request_key'])->first();
                if ($existing) {
                    if ($existing->user_id !== $userId || $existing->terminal_number !== $terminal || $existing->opening_balance !== $opening) {
                        $this->invalid('request_key', 'A solicitação já corresponde a outra abertura de caixa.');
                    }
                    return $existing;
                }
                if (SupermarketShift::where('user_id', $userId)->where('open_slot', true)->first()) {
                    $this->invalid('shift', 'Você já tem um caixa aberto. Encerre o turno antes de abrir outro.');
                }
                app(CashService::class)->lockOpenDay(now()->toDateString());
                if (SupermarketShift::where('terminal_number', $terminal)->where('open_slot', true)->lockForUpdate()->first()) {
                    $this->invalid('terminal_number', 'Este caixa já está aberto. Escolha outro número disponível.');
                }
                $shift = SupermarketShift::create([
                    'user_id' => $userId, 'terminal_number' => $terminal, 'open_slot' => true,
                    'open_request_key' => $data['request_key'], 'opened_at' => now(),
                    'opening_balance' => $opening, 'sale_count' => 0, 'received_by_method' => [],
                ]);
                // An outstanding cart created before shifts existed stays with its operator.
                $legacy = SupermarketCart::where('user_id', $userId)->where('status', 'active')
                    ->whereNotIn('id', DB::connection('tenant')->table('supermarket_shift_carts')->select('cart_id'))
                    ->lockForUpdate()->get();
                foreach ($legacy as $cart) {
                    $this->bind($cart, $shift);
                }
                return $shift;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            if (SupermarketShift::where('open_request_key', $data['request_key'])->exists()) {
                $this->invalid('request_key', 'A solicitação já corresponde a outra abertura de caixa.');
            }
            throw $e;
        }
    }

    public function close(int $shiftId, array $data, int $userId): SupermarketShift
    {
        $data = Validator::make($data, [
            'request_key' => 'required|uuid',
            'counted_balance' => 'required|numeric',
            'reason' => 'required|in:lunch,end,other',
        ])->validate();
        $counted = Decimal::input((string) $data['counted_balance'], 2, 'counted_balance', '0', '99999999999999999.99');
        try {
            return DB::connection('tenant')->transaction(function () use ($shiftId, $data, $userId, $counted) {
                app(Supermarket::class)->actor($userId);
                $shift = SupermarketShift::where('user_id', $userId)->lockForUpdate()->findOrFail($shiftId);
                if ($shift->close_request_key === $data['request_key']) {
                    if ($shift->counted_balance !== $counted || $shift->closing_reason !== $data['reason']) {
                        $this->invalid('request_key', 'A contagem ou o motivo diverge do fechamento já registrado.');
                    }
                    return $shift;
                }
                if ($shift->closed_at) {
                    $this->invalid('shift', 'Este turno já foi encerrado.');
                }
                $carts = SupermarketCart::where('user_id', $userId)->where('status', 'active')->lockForUpdate()->get();
                foreach ($carts as $cart) {
                    if ($cart->items) {
                        $this->invalid('shift', 'Conclua ou suspenda a venda em atendimento antes de encerrar o caixa.');
                    }
                    $cart->update(['status' => 'cancelled', 'version' => $cart->version + 1,
                        'cancellation_reason' => 'Atendimento vazio ao encerrar o turno.']);
                    OperationRecord::create(['version' => 1, 'user_id' => $userId, 'action' => 'supermarket_cart_cancelled',
                        'before' => ['status' => 'active'], 'after' => ['cart_id' => $cart->id, 'shift_id' => $shift->id,
                            'status' => 'cancelled', 'reason' => $cart->cancellation_reason], 'created_at' => now()]);
                }
                $expected = $this->expected($shift);
                $shift->update([
                    'open_slot' => null, 'close_request_key' => $data['request_key'], 'closed_at' => now(),
                    'closing_reason' => $data['reason'], 'counted_balance' => $counted,
                    'expected_balance' => $expected, 'difference' => bcsub($counted, $expected, 2),
                ]);
                return $shift;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            if (SupermarketShift::where('close_request_key', $data['request_key'])->exists()) {
                $this->invalid('request_key', 'A solicitação já corresponde a outro fechamento de caixa.');
            }
            throw $e;
        }
    }

    public function expected(SupermarketShift $shift): string
    {
        return bcadd($shift->opening_balance, $shift->received_by_method['cash'] ?? '0.00', 2);
    }

    /** The caller locked the operator row before locking the cart or shift. */
    public function requireOpen(int $userId): SupermarketShift
    {
        $shift = SupermarketShift::where('user_id', $userId)->where('open_slot', true)->lockForUpdate()->first();
        if (!$shift) {
            $this->invalid('shift', 'Abra o seu caixa antes de iniciar ou continuar o atendimento.');
        }
        return $shift;
    }

    public function forCart(SupermarketCart $cart): SupermarketShift
    {
        $shift = $this->requireOpen((int) $cart->user_id);
        $link = DB::connection('tenant')->table('supermarket_shift_carts')->where('cart_id', $cart->id)->lockForUpdate()->first();
        if ($cart->status !== 'suspended' && (!$link || (int) $link->shift_id !== $shift->id)) {
            $this->invalid('shift', 'Esta venda pertence a outro turno. Atualize o caixa.');
        }
        return $shift;
    }

    public function bind(SupermarketCart $cart, SupermarketShift $shift): void
    {
        DB::connection('tenant')->table('supermarket_shift_carts')->updateOrInsert(
            ['cart_id' => $cart->id], ['shift_id' => $shift->id]
        );
    }

    /** Called only on first checkout, inside the sale transaction, while the shift is locked. */
    public function recordSale(SupermarketShift $shift, Sale $sale): void
    {
        $payments = Payment::join('financial_entries as entries', 'entries.id', '=', 'payments.entry_id')
            ->where('entries.sale_id', $sale->id)->where('payments.direction', 'in')
            ->groupBy('payments.method')->selectRaw('payments.method, SUM(payments.amount) AS received')
            ->pluck('received', 'method');
        $totals = $shift->received_by_method;
        foreach ($payments as $method => $amount) {
            $totals[$method] = bcadd($totals[$method] ?? '0.00', (string) $amount, 2);
        }
        $shift->update(['received_by_method' => $totals, 'sale_count' => $shift->sale_count + 1]);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
