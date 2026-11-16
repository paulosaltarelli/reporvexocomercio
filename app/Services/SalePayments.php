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
        return ['payments'=>'sometimes|array|min:1|max:10', 'payments.*.payment_option'=>'required|string|max:60',
            'payments.*.amount'=>'required|numeric|min:0.01|max:999999999999.99|decimal:0,2',
            'payments.*.paid'=>'required|boolean', 'payments.*.due_date'=>'nullable|date_format:Y-m-d',
            'payments.*.installments'=>'nullable|integer|min:1|max:24',
            'payments.*.cash_received'=>'nullable|numeric|min:0|max:999999999999.99|decimal:0,2'];
    }

    public function record(Sale $sale, array $input, int $actor): void
    {
        if(\Illuminate\Support\Facades\DB::connection('tenant')->transactionLevel()===0)throw new \LogicException('O plano de pagamento exige uma transação de venda.');
        $input=Validator::make(['payments'=>$input],self::rules())->validate()['payments'];
        $rows=[];$sum='0.00';$count=0;
        foreach(array_values($input) as $i=>$row){
            $choice=app(PaymentMethods::class)->resolve($row['payment_option'],'cash');
            $amount=Decimal::input((string)$row['amount'],2,'payments.'.$i.'.amount','0.01');
            $paid=(bool)$row['paid'];$parts=$paid?1:(int)($row['installments']??1);
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
            $rows[]=$choice+['amount'=>$amount,'paid'=>$paid,'due_date'=>$date,'installments'=>$parts,'cash_received'=>$tendered,'change'=>$change];
            $sum=bcadd($sum,$amount,2);$count+=$parts;
        }
        if(bccomp($sum,$sale->total,2)!==0 || $count>24)throw ValidationException::withMessages(['payments'=>'A soma das formas deve corresponder exatamente ao total. Limite de 24 parcelas no conjunto.']);
        $key=Uuid::uuid5(Uuid::NAMESPACE_URL,'vendra:sale-plan:'.$sale->request_key)->toString();
        $number=0;
        foreach($rows as $i=>&$row){
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
        }
        unset($row);
        OperationRecord::create(['version'=>0,'user_id'=>$actor,'action'=>'sale_payment_plan','before'=>[],'after'=>['target_id'=>$sale->id,'rows'=>$rows,'total'=>$sum],'created_at'=>now()]);
    }

    public function rows(int $saleId): array
    {
        return OperationRecord::where('action','sale_payment_plan')->where('after->target_id',$saleId)->value('after')['rows']??[];
    }
}
