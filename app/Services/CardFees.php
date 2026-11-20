<?php
namespace App\Services;

use App\Models\{CompanyOperation,OperationRecord,User};
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CardFees
{
    public function all(): array { return CompanyOperation::find(1)?->configuration['_card_brands']??[]; }
    public function options(?string $type=null): array {return array_filter($this->all(),fn($b)=>$b['active']&&(!$type||$b['type']===$type));}
    public static function rules(string $prefix=''): array
    {
        return [$prefix.'card_brand'=>'nullable|string|max:60',$prefix.'card_installments'=>'nullable|integer|min:1|max:24',
            $prefix.'fee_policy'=>'nullable|in:absorb,pass',$prefix.'fee_reviewed'=>'nullable|boolean',
            $prefix.'rate_seen'=>'nullable|numeric|min:0|max:99.99|decimal:0,2',
            $prefix.'charged_seen'=>'nullable|numeric|min:0|max:999999999999.99|decimal:0,2'];
    }
    public function save(array $input,int $actor): void
    {
        abort_unless(\App\Services\InstallerAccess::adminActor($actor),403);
        $input['name']=trim((string)($input['name']??''));
        $d=Validator::make($input,['id'=>'nullable|uuid','version'=>'required|integer|min:0','name'=>'required|string|min:2|max:80','type'=>'required|in:credit,debit','policy'=>'required|in:absorb,pass','active'=>'required|boolean','rates'=>'required|array','rates.*'=>'nullable|numeric|min:0|max:99.99|decimal:0,2'])->validate();
        $rates=[];
        foreach($d['rates'] as $n=>$rate){
            if($rate===null||$rate==='')continue;
            if(!ctype_digit((string)$n)||(int)$n<1||(int)$n>24||($d['type']==='debit'&&(int)$n!==1))throw ValidationException::withMessages(['rates'=>'Use de 1 a 24 parcelas no crédito e somente 1 no débito.']);
            $rates[(int)$n]=Decimal::input((string)$rate,2,'rates','0','99.99');
        }
        if(!$rates)throw ValidationException::withMessages(['rates'=>'Informe ao menos uma taxa. Zero significa taxa zero; vazio significa parcelamento indisponível.']);
        ksort($rates);
        DB::connection('tenant')->transaction(function()use($d,$rates,$actor){
            CompanyOperation::insertOrIgnore(['id'=>1,'configuration'=>json_encode(CompanyOperations::DEFAULTS)]);
            $row=CompanyOperation::whereKey(1)->lockForUpdate()->firstOrFail();
            if($row->version!==(int)$d['version'])throw ValidationException::withMessages(['version'=>'O cadastro mudou. Atualize antes de salvar.']);
            $brands=$row->configuration['_card_brands']??[];$id=$d['id']??(string)Str::uuid();
            if(isset($d['id'])&&!isset($brands[$id]))abort(404);
            foreach($brands as $other=>$brand)if($other!==$id&&$brand['type']===$d['type']&&mb_strtolower($brand['name'])===mb_strtolower($d['name']))throw ValidationException::withMessages(['name'=>'Já existe esta bandeira para este tipo de cartão.']);
            $before=$brands[$id]??[];$brands[$id]=['name'=>$d['name'],'type'=>$d['type'],'policy'=>$d['policy'],'active'=>(bool)$d['active'],'rates'=>$rates];
            $configuration=$row->configuration;$configuration['_card_brands']=$brands;$row->update(['configuration'=>$configuration,'version'=>$row->version+1,'updated_by'=>$actor]);
            OperationRecord::create(['version'=>$row->version,'user_id'=>$actor,'action'=>'card_brand','before'=>$before,'after'=>['id'=>$id]+$brands[$id],'created_at'=>now()]);
        },3);
    }
    public function quote(string $base,string $type,array $input): array
    {
        $empty=['base_amount'=>$base,'amount'=>$base,'fee'=>'0.00','passed_fee'=>'0.00','merchant_net'=>$base,'brand_name'=>null,'card_brand'=>null,'card_installments'=>1,'fee_policy'=>'absorb','rate'=>'0.00'];
        if(empty($input['card_brand'])){
            if($this->options($type)&&in_array($type,['credit','debit'],true))throw ValidationException::withMessages(['card_brand'=>'Selecione uma bandeira e confira a taxa antes de finalizar.']);
            return $empty;
        }
        $brand=$this->options($type)[$input['card_brand']]??null;
        $n=(int)($input['card_installments']??1);$policy=$input['fee_policy']??$brand['policy']??'absorb';
        if(!$brand||!isset($brand['rates'][$n])||!in_array($policy,['absorb','pass'],true))throw ValidationException::withMessages(['card_brand'=>'Bandeira ou parcelamento indisponível para esta forma de pagamento.']);
        $rate=$brand['rates'][$n];
        if(!isset($input['rate_seen'])||bccomp(Decimal::input((string)$input['rate_seen'],2,'rate_seen','0','99.99'),$rate,2)!==0)throw ValidationException::withMessages(['rate_seen'=>'A taxa mudou ou não foi conferida. Atualize e revise o pagamento.']);
        $charged=$policy==='pass'?Decimal::round(bcdiv($base,bcsub('1',bcdiv($rate,'100',8),8),12)):$base;
        Decimal::input($charged,2,'payments');
        $fee=Decimal::round(bcdiv(bcmul($charged,$rate,8),'100',8));
        if($policy==='pass'&&(!(bool)($input['fee_reviewed']??false)||!isset($input['charged_seen'])||bccomp((string)$input['charged_seen'],$charged,2)!==0))throw ValidationException::withMessages(['fee_reviewed'=>'Confira e confirme o total com a taxa repassada antes de finalizar.']);
        return ['base_amount'=>$base,'amount'=>$charged,'fee'=>$fee,'passed_fee'=>bcsub($charged,$base,2),'merchant_net'=>bcsub($charged,$fee,2),'brand_name'=>$brand['name'],'card_brand'=>$input['card_brand'],'card_installments'=>$n,'fee_policy'=>$policy,'rate'=>$rate];
    }
}
