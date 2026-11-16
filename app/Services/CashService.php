<?php

namespace App\Services;

use App\Models\CashClosure;
use App\Models\Payment;
use App\Models\OperationRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashService
{
    /** Live overview for an open day, or the immutable snapshot once closed. */
    public function summary(string $date, string $opening = '0.00'): array
    {
        $date = FinancialService::date($date, 'date')->toDateString();
        $opening = Decimal::input($opening, 2, 'opening_balance');
        $closure = CashClosure::where('date', $date)->first();
        if ($closure) {
            return array_merge($closure->snapshot, ['is_closed' => true, 'closure_id' => $closure->id]);
        }

        return $this->ledgerSummary($date, (string)($this->opening($date)?->after['opening_balance']??$opening), false);
    }

    public function opening(string $date): ?OperationRecord
    {
        return OperationRecord::where('action','cash_opened')->where('after->date',$date)->first();
    }

    public function open(array $data, int $actor): void
    {
        $date=FinancialService::date((string)$data['date'],'date')->toDateString();
        if($date!==now()->toDateString())throw ValidationException::withMessages(['date'=>'Abra o caixa na data de hoje.']);
        $amount=Decimal::input((string)$data['opening_balance'],2,'opening_balance');
        DB::connection('tenant')->transaction(function()use($date,$amount,$actor){
            $this->lockOpenDay($date);
            $existing=OperationRecord::where('action','cash_opened')->where('after->date',$date)->sharedLock()->first();
            if($existing){if(bccomp($existing->after['opening_balance'],$amount,2)!==0)throw ValidationException::withMessages(['opening_balance'=>'A abertura já foi registrada com outro valor.']);return;}
            $last=CashClosure::orderByDesc('date')->sharedLock()->first();
            if($last && bccomp($amount,$last->counted_balance,2)!==0)throw ValidationException::withMessages(['opening_balance'=>'Use o valor contado no último fechamento: R$ '.$last->counted_balance]);
            OperationRecord::create(['version'=>0,'user_id'=>$actor,'action'=>'cash_opened','before'=>[],'after'=>['date'=>$date,'opening_balance'=>$amount],'created_at'=>now()]);
        },3);
    }

    public function movement(array $data, int $actor): void
    {
        $direction=($data['type']??'')==='supply'?'in':'out';
        if(!in_array($data['type']??'', ['supply','withdrawal'],true))throw ValidationException::withMessages(['type'=>'Movimento inválido.']);
        if(!Str::isUuid($data['request_key']??''))throw ValidationException::withMessages(['request_key'=>'Chave inválida.']);
        $description=trim((string)($data['description']??''));
        if(mb_strlen($description)<3||mb_strlen($description)>150)throw ValidationException::withMessages(['description'=>'Informe o motivo com 3 a 150 caracteres.']);
        DB::connection('tenant')->transaction(function()use($data,$actor,$direction,$description){
            $entries=app(FinancialService::class)->schedule(['request_key'=>$data['request_key'],'amount'=>$data['amount'],'direction'=>$direction,'due_date'=>now()->toDateString(),'description'=>$description,'category'=>$direction==='in'?'Suprimento de caixa':'Sangria de caixa'],$actor);
            $entry=$entries->first();
            if(bccomp($entry->paid_amount,'0',2)===0){
                $this->lockOpenDay(now()->toDateString());
                $opened=OperationRecord::where('action','cash_opened')->where('after->date',now()->toDateString())->sharedLock()->first();
                if(!$opened)throw ValidationException::withMessages(['opening_balance'=>'Registre a abertura antes de sangrias ou suprimentos.']);
                $summary=$this->ledgerSummary(now()->toDateString(),$opened->after['opening_balance'],true);
                if($direction==='out'&&bccomp($summary['expected_balance'],$entry->amount,2)<0)throw ValidationException::withMessages(['amount'=>'A sangria excede o dinheiro disponível na gaveta.']);
            }
            app(FinancialService::class)->pay($entry->id,$entry->amount,now()->toDateString(),'cash',$actor,\Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL,'vendra:cash-movement:'.$data['request_key'])->toString());
        },3);
    }

    /**
     * Must run inside the transaction that inserts Payment. Parent/entry locks
     * precede calendar/day locks; no Payment row is locked before this gate.
     */
    public function lockOpenDay(string $date, ?string $replayKey = null): ?Payment
    {
        $date = FinancialService::date($date, 'paid_on')->toDateString();
        $db = DB::connection('tenant');
        if ($db->transactionLevel() === 0) {
            throw new \LogicException('O bloqueio do dia exige uma transação financeira ativa.');
        }
        $calendar = $db->table('cash_closure_locks')->where('id', 1)->sharedLock()->first();
        if (! $calendar) {
            throw new \LogicException('Execute a migração de caixa antes de registrar pagamentos.');
        }
        // A locking current read is necessary when a surrounding transaction
        // already established an older REPEATABLE READ snapshot.
        $last = CashClosure::orderByDesc('date')->sharedLock()->first();
        $db->table('cash_days')->insertOrIgnore(['date' => $date]);
        $day = $db->table('cash_days')->where('date', $date)->lockForUpdate()->first();
        // Immutable existing payments may be replayed after closing. This current
        // read happens AFTER calendar/day locks, avoiding stale snapshots and lock inversion.
        if ($replayKey !== null) {
            $existing = Payment::where('request_key', $replayKey)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
        }
        if ($day->closed_at !== null || ($last && $date <= $last->date->toDateString())) {
            throw ValidationException::withMessages(['paid_on' => 'Este dia financeiro já foi consolidado. Registre a correção identificada em uma data aberta posterior ao último fechamento.']);
        }

        return null;
    }

    public function close(array $data, int $userId): CashClosure
    {
        $key = (string) ($data['request_key'] ?? '');
        if (! Str::isUuid($key)) {
            throw ValidationException::withMessages(['request_key' => 'Chave do fechamento inválida.']);
        }
        $date = FinancialService::date((string) $data['date'], 'date');
        if ($date->toDateString() > now()->toDateString()) {
            throw ValidationException::withMessages(['date' => 'Não é possível fechar uma data futura.']);
        }
        $date = $date->toDateString();
        $opening = Decimal::input((string) $data['opening_balance'], 2, 'opening_balance');
        $counted = Decimal::input((string) $data['counted_balance'], 2, 'counted_balance');
        $notes = isset($data['notes']) ? trim((string) $data['notes']) : null;
        $notes = $notes === '' ? null : $notes;
        if ($notes !== null && mb_strlen($notes) > 2000) {
            throw ValidationException::withMessages(['notes' => 'As observações devem ter até 2000 caracteres.']);
        }

        $counts=\Illuminate\Support\Facades\Validator::make($data,['counted_methods'=>'sometimes|array:pix,credit,debit,transfer,invoice','counted_methods.*'=>'required|numeric|min:-999999999999.99|max:999999999999.99|decimal:0,2'])->validate()['counted_methods']??[];
        foreach($counts as &$value)$value=bcadd((string)$value,'0',2);unset($value);ksort($counts);
        return DB::connection('tenant')->transaction(function () use ($key, $date, $opening, $counted, $notes, $userId,$counts) {
            $db = DB::connection('tenant');
            // Exclusive calendar lock serializes closings and waits for all
            // payment writers; closings never need sale or financial-entry locks.
            $calendar = $db->table('cash_closure_locks')->where('id', 1)->lockForUpdate()->first();
            if (! $calendar) {
                throw new \LogicException('Execute a migração de caixa antes de fechar o dia.');
            }
            $existing = CashClosure::where('request_key', $key)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->date->toDateString() !== $date || (int) $existing->user_id !== $userId
                    || bccomp($existing->opening_balance, $opening, 2) !== 0
                    || bccomp($existing->counted_balance, $counted, 2) !== 0
                    || $existing->notes !== $notes || ($existing->snapshot['counted_methods']??[])!=$counts) {
                    throw ValidationException::withMessages(['request_key' => 'Esta chave já pertence a outro fechamento.']);
                }

                return $existing;
            }
            $db->table('cash_days')->insertOrIgnore(['date' => $date]);
            $day = $db->table('cash_days')->where('date', $date)->lockForUpdate()->first();
            $last = CashClosure::orderByDesc('date')->lockForUpdate()->first();
            if ($day->closed_at !== null || ($last && $date <= $last->date->toDateString())) {
                throw ValidationException::withMessages(['date' => 'O fechamento é imutável e deve seguir a ordem das datas, após o último dia fechado.']);
            }
            if ($last && bccomp($opening, $last->counted_balance, 2) !== 0) {
                throw ValidationException::withMessages(['opening_balance' => 'A abertura deve ser igual ao valor contado no último fechamento: R$ '.$last->counted_balance.'.']);
            }
            $earlier = Payment::where('paid_on', '<', $date)
                ->when($last, fn ($query) => $query->where('paid_on', '>', $last->date->toDateString()))
                ->orderBy('paid_on')->orderBy('id')->lockForUpdate()->first();
            if ($earlier) {
                throw ValidationException::withMessages(['date' => 'Feche primeiro o dia com movimento financeiro mais antigo ainda aberto: '.$earlier->paid_on->format('d/m/Y').'.']);
            }
            $registered=$this->opening($date);
            if($registered && bccomp($registered->after['opening_balance'],$opening,2)!==0)throw ValidationException::withMessages(['opening_balance'=>'Use a abertura já registrada para o dia.']);
            $snapshot = $this->ledgerSummary($date, $opening, true);
            $snapshot['counted_methods']=$counts;
            $snapshot['differences_by_method']=[];
            foreach($counts as $method=>$amount)$snapshot['differences_by_method'][$method]=bcsub($amount,$snapshot['totals_by_method'][$method]['net'],2);
            unset($snapshot['is_closed'], $snapshot['closure_id']);
            $difference = bcsub($counted, $snapshot['expected_balance'], 2);
            foreach (['cash_in' => $snapshot['cash_in'], 'cash_out' => $snapshot['cash_out'], 'expected_balance' => $snapshot['expected_balance'], 'difference' => $difference] as $field => $value) {
                if (bccomp($value, '-999999999999.99', 2) < 0 || bccomp($value, '999999999999.99', 2) > 0) {
                    throw ValidationException::withMessages([$field => 'O total ultrapassa o limite monetário suportado para o fechamento.']);
                }
            }
            $closedAt = now();
            $closure = CashClosure::create([
                'request_key' => $key, 'date' => $date, 'user_id' => $userId,
                'opening_balance' => $opening, 'cash_in' => $snapshot['cash_in'], 'cash_out' => $snapshot['cash_out'],
                'expected_balance' => $snapshot['expected_balance'], 'counted_balance' => $counted,
                'difference' => $difference, 'notes' => $notes, 'snapshot' => $snapshot, 'closed_at' => $closedAt,
            ]);
            $db->table('cash_days')->where('date', $date)->update(['closed_at' => $closedAt]);

            return $closure;
        }, 3);
    }

    private function ledgerSummary(string $date, string $opening, bool $currentRead): array
    {
        $query = Payment::where('paid_on', $date)->orderBy('id');
        if ($currentRead) {
            $query->lockForUpdate();
        }
        $payments = $query->get();
        $totals = [];
        foreach (['cash', 'pix', 'credit', 'debit', 'transfer', 'invoice'] as $method) {
            $totals[$method] = ['in' => '0.00', 'out' => '0.00', 'net' => '0.00'];
        }
        $byUser=[];$byName=[];
        $labels=app(PaymentMethods::class)->labels('payment_method_used',$payments->modelKeys());
        foreach ($payments as $payment) {
            if (! in_array($payment->direction, ['in', 'out'], true)) {
                throw new \LogicException('O histórico financeiro contém uma direção inválida.');
            }
            $totals[$payment->method] ??= ['in' => '0.00', 'out' => '0.00', 'net' => '0.00'];
            $byUser[$payment->user_id]??=['in'=>'0.00','out'=>'0.00'];
            $byUser[$payment->user_id][$payment->direction]=bcadd($byUser[$payment->user_id][$payment->direction],$payment->amount,2);
            $label=$labels[$payment->id]??PaymentMethods::TYPES[$payment->method]??$payment->method;
            $namedKey=$payment->method.'|'.$label;
            $byName[$namedKey]??=['label'=>$label,'in'=>'0.00','out'=>'0.00'];
            $byName[$namedKey][$payment->direction]=bcadd($byName[$namedKey][$payment->direction],$payment->amount,2);
            $totals[$payment->method][$payment->direction] = bcadd($totals[$payment->method][$payment->direction], $payment->amount, 2);
        }
        foreach ($totals as &$total) {
            $total['net'] = bcsub($total['in'], $total['out'], 2);
        }
        unset($total);

        return [
            'date' => $date, 'opening_balance' => $opening,
            'cash_in' => $totals['cash']['in'], 'cash_out' => $totals['cash']['out'],
            'expected_balance' => bcadd($opening, $totals['cash']['net'], 2),
            'by_user'=>$byUser,'by_name'=>array_values($byName),
            'totals_by_method' => $totals, 'payment_count' => $payments->count(),
            'is_closed' => false, 'closure_id' => null,
        ];
    }
}
