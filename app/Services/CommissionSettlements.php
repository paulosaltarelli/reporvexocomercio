<?php
namespace App\Services;

use App\Models\{CompanyOperation,FinancialEntry,OperationRecord,Payment,Sale,Seller,User};
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CommissionSettlements
{
    public const ACTION='commission_closed';
    public function filters(array $input): array
    {
        $d=Validator::make($input,['from'=>'required|date_format:Y-m-d','to'=>'required|date_format:Y-m-d|after_or_equal:from','salesperson_id'=>'required|integer|exists:tenant.sellers,id'])->validate();
        if(FinancialService::date($d['from'],'from')->diffInDays(FinancialService::date($d['to'],'to'))>366||$d['to']>now()->toDateString())throw ValidationException::withMessages(['to'=>'Escolha um período de até 366 dias, sem data futura.']);
        return $d;
    }
    public function records(?int $sellerId=null)
    {
        return OperationRecord::where('action',self::ACTION)->when($sellerId,fn($q)=>$q->where('after->salesperson_id',$sellerId));
    }
    public function balances(?int $sellerId=null): array
    {
        $records=$this->records($sellerId)->get();$entries=FinancialEntry::whereIn('id',$records->map(fn($r)=>$r->after['entry_id']))->get();
        $totals=['closed'=>'0.00','paid'=>'0.00','balance'=>'0.00'];foreach($entries as $entry){$totals['closed']=bcadd($totals['closed'],$entry->amount,2);$totals['paid']=bcadd($totals['paid'],$entry->paid_amount,2);$totals['balance']=bcadd($totals['balance'],$entry->balance,2);}return $totals;
    }
    public function record(int $id): OperationRecord {return $this->records()->findOrFail($id);}
    public function preview(array $input,bool $lock=false): array
    {
        $d=$this->filters($input);$id=(int)$d['salesperson_id'];
        $sellerQuery=Seller::whereKey($id);if($lock)$sellerQuery->sharedLock();$seller=$sellerQuery->firstOrFail();
        $salesQuery=Sale::where('salesperson_id',$id)
            ->where('commission_total','>','0')->where('created_at','<=',$d['to'].' 23:59:59')->orderBy('id');
        if($lock)$salesQuery->lockForUpdate();
        $sales=$salesQuery->get(['id','commission_total','commission_basis','total','status','created_at','updated_at']);
        // Sales are locked first, as in the payment and reversal transactions. The
        // shared locking read also sees receipts committed while that lock was pending.
        $receipts=[];
        foreach(array_chunk($sales->modelKeys(),1000) as $saleIds){
            $query=FinancialEntry::query()->toBase()
                ->leftJoin('payments',function($join)use($d){
                    $join->on('payments.entry_id','=','financial_entries.id')->where('payments.paid_on','<=',$d['to']);
                })
                ->whereIntegerInRaw('financial_entries.sale_id',$saleIds)
                ->groupBy('financial_entries.sale_id')
                ->select('financial_entries.sale_id')
                ->selectRaw('MAX(financial_entries.legacy_paid_amount > 0) AS has_legacy')
                ->selectRaw("COALESCE(SUM(CASE WHEN payments.kind = 'refund' THEN -payments.amount ELSE payments.amount END), 0.00) AS received");
            if($lock)$query->sharedLock();
            foreach($query->get() as $receipt)$receipts[$receipt->sale_id]=$receipt;
        }
        $ledger=$this->records($id)->select('after')->orderBy('id');
        if($lock)$ledger->sharedLock();
        $records=$ledger->cursor();$accounted=[];$latest=null;
        foreach($records as $record){$after=$record->after;$latest=max($latest??'0000-00-00',$after['to']);foreach($after['lines'] as $line)$accounted[$line['sale_id']]=bcadd($accounted[$line['sale_id']]??'0.00',$line['amount'],2);}
        if($latest!==null&&$d['to']<$latest)throw ValidationException::withMessages(['to'=>'Já há fechamento posterior para este vendedor. Use uma data igual ou posterior a '.$latest.'.']);
        $lines=[];$total='0.00';$positive='0.00';$negative='0.00';$awaiting='0.00';$legacy=0;
        foreach($sales as $sale){
            $receipt=$receipts[$sale->id]??null;
            $received=(string)($receipt->received??'0.00');
            if(($receipt->has_legacy??false)&&$sale->commission_basis!=='accrual')$legacy++;
            $received=bccomp($received,'0',2)<0?'0.00':(bccomp($received,$sale->total,2)>0?(string)$sale->total:$received);
            $cancelled=$sale->status==='cancelled'&&$sale->updated_at->format('Y-m-d')<=$d['to'];
            $eligible=$cancelled?'0.00':($sale->commission_basis==='accrual'?(string)$sale->commission_total:(bccomp($sale->total,'0',2)>0?Decimal::round(bcdiv(bcmul($sale->commission_total,$received,8),$sale->total,8)):'0.00'));
            $already=$accounted[$sale->id]??'0.00';$amount=bcsub($eligible,$already,2);
            $awaiting=bcadd($awaiting,$cancelled?'0.00':bcsub($sale->commission_total,$eligible,2),2);
            if(bccomp($amount,'0',2)===0)continue;
            $line=['sale_id'=>$sale->id,'sold_on'=>$sale->created_at->format('Y-m-d'),'basis'=>$sale->commission_basis,'sale_total'=>(string)$sale->total,'commission_total'=>(string)$sale->commission_total,'received'=>$received,'eligible'=>$eligible,'already_closed'=>$already,'amount'=>$amount,'cancelled'=>$cancelled];
            $lines[]=$line;$total=bcadd($total,$amount,2);if(bccomp($amount,'0',2)>0)$positive=bcadd($positive,$amount,2);else$negative=bcadd($negative,$amount,2);
        }
        $snapshot=['schema'=>1,'salesperson_id'=>$id,'seller_name'=>$seller->name,'from'=>$d['from'],'to'=>$d['to'],'lines'=>$lines,'positive'=>$positive,'adjustments'=>$negative,'total'=>$total,'awaiting'=>$awaiting,'legacy_sales'=>$legacy];
        return $snapshot+['preview_hash'=>hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR))];
    }
    public function close(array $input,int $actor): OperationRecord
    {
        $this->authorize($actor,'commissions.close');
        $d=Validator::make($input,['request_key'=>'required|uuid','preview_hash'=>'required|string|size:64','due_date'=>'required|date_format:Y-m-d','reviewed'=>'required|accepted'])->validate();$this->filters($input);
        return DB::connection('tenant')->transaction(function()use($input,$d,$actor){
            CompanyOperation::insertOrIgnore(['id'=>1,'configuration'=>json_encode(CompanyOperations::DEFAULTS),'version'=>0,'created_at'=>now(),'updated_at'=>now()]);$config=CompanyOperation::whereKey(1)->lockForUpdate()->firstOrFail();
            $existing=$this->records()->where('after->request_key',$d['request_key'])->first();
            if($existing){$a=$existing->after;if((int)$existing->user_id!==$actor||(int)$a['salesperson_id']!==(int)$input['salesperson_id']||$a['from']!==$input['from']||$a['to']!==$input['to']||$a['due_date']!==$d['due_date'])throw ValidationException::withMessages(['request_key'=>'Chave já usada em outro fechamento.']);return $existing;}
            $snapshot=$this->preview($input,true);
            if(!hash_equals($snapshot['preview_hash'],$d['preview_hash']))throw ValidationException::withMessages(['preview_hash'=>'A apuração mudou. Atualize a prévia e confira novamente.']);
            if(bccomp($snapshot['total'],'0',2)<=0)throw ValidationException::withMessages(['total'=>'Não há saldo positivo para fechar. Ajustes negativos continuam pendentes e serão compensados na próxima apuração positiva.']);
            if($snapshot['legacy_sales'])throw ValidationException::withMessages(['legacy'=>'Existem baixas legadas sem data comprovada. Reconcilie esses recebimentos antes de fechar.']);
            $entry=app(FinancialService::class)->schedule(['request_key'=>$d['request_key'],'direction'=>'out','description'=>'Comissões · '.mb_substr($snapshot['seller_name'],0,90).' · '.$snapshot['from'].' a '.$snapshot['to'],'category'=>'Comissões de vendas','amount'=>$snapshot['total'],'due_date'=>$d['due_date'],'installments'=>1],$actor)->first();
            unset($snapshot['preview_hash']);$snapshot+=['request_key'=>$d['request_key'],'entry_id'=>$entry->id,'due_date'=>$d['due_date']];
            return OperationRecord::create(['version'=>$config->version,'user_id'=>$actor,'action'=>self::ACTION,'before'=>[],'after'=>$snapshot,'created_at'=>now()]);
        },3);
    }
    public function pay(int $id,array $input,int $actor): Payment
    {
        $this->authorize($actor,'commissions.pay');$record=$this->record($id);
        if(!empty($input['payment_option']))$input['payment_method']=app(PaymentMethods::class)->resolve($input['payment_option'],$input['payment_method']??'cash')['type'];
        $d=Validator::make($input,['request_key'=>'required|uuid','amount'=>'required|numeric|min:0.01|max:999999999999.99|decimal:0,2','paid_on'=>'required|date_format:Y-m-d','payment_method'=>'required|in:cash,pix,credit,debit,transfer,invoice','payment_option'=>'nullable|string|max:60'])->validate();
        if($d['paid_on']>now()->toDateString())throw ValidationException::withMessages(['paid_on'=>'O pagamento não pode ter data futura.']);
        return app(FinancialService::class)->pay((int)$record->after['entry_id'],(string)$d['amount'],$d['paid_on'],$d['payment_method'],$actor,$d['request_key'],$d['payment_option']??null);
    }
    public function authorize(int $actor,string $permission): void
    {
        abort_unless(User::where('active',true)->find($actor)?->allows($permission),403);
    }
    public function own(User $user): Seller
    {
        return Seller::where('user_id',$user->id)->firstOrFail();
    }
}
