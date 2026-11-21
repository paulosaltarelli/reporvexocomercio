<?php

namespace App\Services;

use App\Models\{FinancialEntry, OperationRecord, Sale};
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Runs only inside Checkout's locked sale transaction. */
final class SalePayments
{
    public static function rules(): array
    {
        return array_merge(CardFees::rules(),CardFees::rules('payments.*.'),['payments'=>'sometimes|array|min:1|max:10', 'payments.*.payment_option'=>'required|string|max:60',
            'payments.*.amount'=>'required|numeric|min:0.01|max:999999999999.99|decimal:0,2',
            'payments.*.paid'=>'required|boolean', 'payments.*.due_date'=>'nullable|date_format:Y-m-d',
            'payments.*.installments'=>'nullable|integer|min:1|max:24',
            'payments.*.cash_received'=>'nullable|numeric|min:0|max:999999999999.99|decimal:0,2']);
    }

    public function record(Sale $sale, array $input, int $actor): void
    {
        $this->recordInternal($sale, $input, $actor, []);
    }

    /** Existing restaurant receipts are selected from the locked, persisted agreement only. */
    public function recordWithPrepayments(Sale $sale, array $input, int $actor, \App\Models\RestaurantOrder $order): void
    {
        $expected = Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:restaurant:'.$order->request_key)->toString();
        if ($sale->request_key !== $expected) throw new \LogicException('A venda não pertence à comanda informada.');
        $receipts = \App\Models\RestaurantOrderPayment::where('restaurant_order_id', $order->id)->whereNull('refunded_at')
            ->orderBy('id')->lockForUpdate()->get()->all();
        if (!$receipts) throw new \LogicException('Não existem recebimentos prévios para esta comanda.');
        $this->recordInternal($sale, $input, $actor, $receipts);
    }

    private function recordInternal(Sale $sale, array $input, int $actor, array $prepayments): void
    {
        if(\Illuminate\Support\Facades\DB::connection('tenant')->transactionLevel()===0)throw new \LogicException('O plano de pagamento exige uma transação de venda.');
        $input = $input === [] && $prepayments ? [] : Validator::make(['payments'=>$input],self::rules())->validate()['payments'];
        ksort($input,SORT_NUMERIC);
        $rows=[];$sum='0.00';$chargedTotal='0.00';$passedTotal='0.00';$count=0;
        $prepaidEntries = [];
        foreach ($prepayments as $receipt) {
            $entry = FinancialEntry::lockForUpdate()->findOrFail($receipt->financial_entry_id);
            $payment = \App\Models\Payment::whereKey($receipt->payment_id)->firstOrFail();
            if (($entry->sale_id && $entry->sale_id !== $sale->id) || $entry->cancelled_at || $entry->direction !== 'in'
                || bccomp($entry->amount, $receipt->amount, 2) !== 0 || bccomp($entry->paid_amount, $receipt->amount, 2) !== 0
                || $payment->entry_id !== $entry->id || $payment->reversal()->exists()) {
                throw ValidationException::withMessages(['payments' => 'Um recebimento prévio precisa de conferência financeira antes de fechar.']);
            }
            $row = $receipt->payment_snapshot;
            $row['restaurant_receipt_id'] = $receipt->id;
            $row['paid'] = true;
            $row['installments'] = 1;
            $rows[] = $row;
            $prepaidEntries[$receipt->id] = $entry;
            $sum = bcadd($sum, $receipt->base_amount, 2);
            $chargedTotal = bcadd($chargedTotal, $receipt->amount, 2);
            $passedTotal = bcadd($passedTotal, $receipt->passed_fee, 2);
            $count++;
        }
        foreach(array_values($input) as $i=>$row){
            $choice=app(PaymentMethods::class)->resolve($row['payment_option'],'cash');
            $amount=Decimal::input((string)$row['amount'],2,'payments.'.$i.'.amount','0.01');
            $quote=app(CardFees::class)->quote($amount,$choice['type'],$row);
            $paid=(bool)$row['paid'];$parts=$paid?1:(int)($quote['card_brand']?$quote['card_installments']:($row['installments']??1));
            if($paid && $choice['type']==='invoice')throw ValidationException::withMessages(['payments'=>'A prazo deve ser registrado como a receber. Escolha o meio efetivamente recebido para uma baixa.']);
            $date=$paid?now()->toDateString():FinancialService::date((string)($row['due_date']??''),'payments.'.$i.'.due_date')->toDateString();
            if(!$paid && $date<now()->toDateString())throw ValidationException::withMessages(['payments'=>'O primeiro vencimento não pode ser anterior à venda.']);
            if(bccomp(bcmul($amount,'100',0),(string)$parts,0)<0)throw ValidationException::withMessages(['payments'=>'Cada parcela deve ter pelo menos um centavo.']);
            $tendered=null;$change='0.00';
            if($paid && $choice['type']==='cash' && isset($row['cash_received'])){
                $tendered=Decimal::input((string)$row['cash_received'],2,'payments.'.$i.'.cash_received');
                if(bccomp($tendered,$amount,2)<0)throw ValidationException::withMessages(['payments'=>'O dinheiro entregue não cobre a parte em dinheiro.']);
                $change=bcsub($tendered,$amount,2);
            }
            $rows[]=$choice+$quote+['paid'=>$paid,'due_date'=>$date,'installments'=>$parts,'cash_received'=>$tendered,'change'=>$change];
            $sum=bcadd($sum,$amount,2);$chargedTotal=bcadd($chargedTotal,$quote['amount'],2);$passedTotal=bcadd($passedTotal,$quote['passed_fee'],2);$count+=$parts;
        }
        if(bccomp($sum,$sale->total,2)!==0 || $count>($prepayments ? 200 : 24))throw ValidationException::withMessages(['payments'=>($prepayments ? 'A soma dos recebimentos anteriores e do saldo deve corresponder ao consumo. Limite de 200 títulos.' : 'A soma das formas deve corresponder exatamente ao total. Limite de 24 parcelas no conjunto.')]);
        Decimal::input($chargedTotal,2,'payments');$sale->update(['total'=>$chargedTotal]);
        $key=Uuid::uuid5(Uuid::NAMESPACE_URL,'vendra:sale-plan:'.$sale->request_key)->toString();
        $number=0;
        foreach($rows as $i=>&$row){
            if (isset($row['restaurant_receipt_id'])) {
                $number++;
                $entry = $prepaidEntries[$row['restaurant_receipt_id']];
                $entry->update(['sale_id' => $sale->id, 'schedule_key' => $key, 'installment_no' => $number, 'installments_count' => $count]);
                $row['entry_ids'] = [$entry->id];
                continue;
            }
            $cents=bcmul($row['amount'],'100',0);$base=bcdiv($cents,(string)$row['installments'],0);$row['entry_ids']=[];
            for($part=1;$part<=$row['installments'];$part++){
                $number++;$value=bcdiv($part===$row['installments']?bcsub($cents,bcmul($base,(string)($part-1),0),0):$base,'100',2);
                $entry=FinancialEntry::create(['request_key'=>Uuid::uuid5(Uuid::NAMESPACE_URL,'vendra:installment:'.$key.':'.$number)->toString(),
                    'schedule_key'=>$key,'installment_no'=>$number,'installments_count'=>$count,'user_id'=>$actor,'sale_id'=>$sale->id,
                    'direction'=>'in','description'=>'Venda #'.$sale->id.' · '.$row['label'].' ('.$part.'/'.$row['installments'].')',
                    'category'=>'Vendas','amount'=>$value,'due_date'=>FinancialService::date($row['due_date'],'due_date')->addMonthsNoOverflow($part-1)->toDateString(),
                    'paid_amount'=>'0.00','legacy_paid_amount'=>'0.00','legacy_import'=>false]);
                $row['entry_ids'][]=$entry->id;
                if($row['paid'])app(FinancialService::class)->pay($entry->id,$value,now()->toDateString(),$row['type'],$actor,
                    Uuid::uuid5(Uuid::NAMESPACE_URL,'vendra:immediate-payment:'.$entry->request_key)->toString(),$row['id']);
            }
            $row['fee_entry_ids']=[];
            if(bccomp($row['fee'],'0',2)>0){
                $feeEntries=app(FinancialService::class)->schedule(['request_key'=>Uuid::uuid5(Uuid::NAMESPACE_URL,'vendra:card-fee:'.$sale->request_key.':'.$i)->toString(),'amount'=>$row['fee'],'direction'=>'out','description'=>'Taxa '.$row['brand_name'].' · venda #'.$sale->id,'category'=>'Taxas de cartão','due_date'=>$row['due_date'],'installments'=>min($row['installments'],(int)bcmul($row['fee'],'100',0))],$actor);
                foreach($feeEntries as $feeEntry){$row['fee_entry_ids'][]=$feeEntry->id;if($row['paid'])app(FinancialService::class)->pay($feeEntry->id,$feeEntry->amount,now()->toDateString(),$row['type'],$actor,Uuid::uuid5(Uuid::NAMESPACE_URL,'vendra:card-fee-paid:'.$feeEntry->request_key)->toString(),$row['id']);}
            }
        }
        unset($row);
        if ($prepayments) {
            $entries = FinancialEntry::where('sale_id', $sale->id)->where('direction', 'in')->whereNull('cancelled_at')->get();
            $paid = $entries->every(fn ($entry) => bccomp($entry->paid_amount, $entry->amount, 2) === 0);
            $planned = $entries->reduce(fn ($sum, $entry) => bcadd($sum, $entry->amount, 2), '0.00');
            $sale->update(['paid' => $paid && bccomp($planned, $sale->total, 2) === 0]);
        }
        OperationRecord::create(['version'=>0,'user_id'=>$actor,'action'=>'sale_payment_plan','before'=>[],'after'=>['target_id'=>$sale->id,'rows'=>$rows,'total'=>$chargedTotal,'base_total'=>$sum,'passed_total'=>$passedTotal],'created_at'=>now()]);
    }

    public function passedTotal(int $saleId): string
    {return OperationRecord::where('action','sale_payment_plan')->where('after->target_id',$saleId)->value('after')['passed_total']??'0.00';}

    public function rows(int $saleId): array
    {
        return OperationRecord::where('action','sale_payment_plan')->where('after->target_id',$saleId)->value('after')['rows']??[];
    }
}
