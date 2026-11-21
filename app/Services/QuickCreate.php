<?php
namespace App\Services;

use App\Models\{CompanyOperation,Customer,OperationRecord,Product};
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Validation\{Rule,ValidationException};
use Ramsey\Uuid\Uuid;

final class QuickCreate
{
    public function create(string $kind,array $input,int $actor): array
    {
        abort_unless(in_array($kind,['customers','products','services'],true),404);
        $permission = $kind === 'customers' ? 'customers.manage' : 'products.manage';
        InstallerAccess::authorizedActor($actor, $permission);
        foreach(['name','sku','barcode','document','email','phone'] as $field)if(is_string($input[$field]??null))$input[$field]=trim($input[$field]);
        $rules=['request_key'=>'required|uuid','name'=>'required|string|max:180'];
        if ($kind === 'customers') $input = CustomerFields::normalize($input);
        $rules+=$kind==='customers'?CustomerFields::RULES:[
            'sku'=>'nullable|string|max:80','barcode'=>'nullable|string|max:80','product_group_id'=>'required|integer|exists:tenant.product_groups,id',
            'unit'=>['required',Rule::in(array_keys(\App\Services\MeasureUnits::catalog(app(\App\Services\CompanyOperations::class)->current())))],'sale_price'=>'required|numeric|min:0|max:999999999999.99|decimal:0,2',
            'controls_stock'=>'required|boolean','initial_quantity'=>'nullable|numeric|min:0|max:999999999|decimal:0,3',
            'initial_cost'=>'nullable|numeric|min:0|max:999999999.9999|decimal:0,4'];
        $data=Validator::make($input,$rules)->validate();$key=$data['request_key'];unset($data['request_key']);ksort($data);
        $hash=hash('sha256',json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        return DB::connection('tenant')->transaction(function()use($data,$key,$hash,$kind,$actor,$permission){
            // A per-tenant current lock serializes retries without introducing another schema.
            CompanyOperation::insertOrIgnore(['id'=>1,'configuration'=>json_encode(CompanyOperations::DEFAULTS),'version'=>0]);
            CompanyOperation::whereKey(1)->lockForUpdate()->firstOrFail();
            $user = InstallerAccess::authorizedActor($actor, $permission);
            $record=OperationRecord::where('action','quick_created')->where('after->request_key',$key)->sharedLock()->first();
            $model=$kind==='customers'?Customer::class:Product::class;
            if($record){
                if((int)$record->user_id!==$actor||$record->after['kind']!==$kind||!hash_equals($record->after['hash'],$hash))throw ValidationException::withMessages(['request_key'=>'Esta tentativa já foi salva com outros dados. Confira o cadastro antes de tentar novamente.']);
                return $this->present($model::findOrFail($record->after['target_id']),$kind);
            }
            if($kind==='customers'){
                $document=\App\Fiscal\TaxpayerDocument::normalize((string)($data['document']??''));
                if(trim((string)($data['document']??''))!==''){
                    if(!\App\Fiscal\TaxpayerDocument::cpf($document)&&!\App\Fiscal\TaxpayerDocument::cnpj($document))throw ValidationException::withMessages(['document'=>'Confira os dígitos do CPF ou CNPJ.']);
                    $existing=Customer::whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(document, '.', ''), '/', ''), '-', ''), ' ', '') = ?",[$document])->first();
                    if($existing)throw ValidationException::withMessages(['document'=>'Este CPF/CNPJ já está cadastrado em '.$existing->name.'. Selecione o cliente existente no atendimento.']);
                }
                $data['document'] = $document ?: null;
                $row = Customer::create($data);
            }else{
                $type=$kind==='services'?'service':'product';$configuration=app(CompanyOperations::class)->current();app(CompanyOperations::class)->assertItem($type,$configuration);
                $stock=(bool)$data['controls_stock'];$qty=(string)($data['initial_quantity']??'0');
                if(($type==='service'||!$configuration['inventory'])&&$stock)throw ValidationException::withMessages(['controls_stock'=>'Este cadastro não permite controle de estoque.']);
                if(bccomp($qty,'0',3)>0){
                    abort_unless($user->allows('stock.manage'),403);
                    if(!$stock||$type!=='product')throw ValidationException::withMessages(['initial_quantity'=>'Saldo inicial exige um produto com controle de estoque.']);
                    if(!isset($data['initial_cost']))throw ValidationException::withMessages(['initial_cost'=>'Informe o custo unitário do saldo inicial.']);
                }
                $sku=$data['sku']??null;$sku=$sku?:'CAD-'.strtoupper(substr(str_replace('-','',$key),0,20));
                Validator::make(['sku'=>$sku,'barcode'=>$data['barcode']??null],['sku'=>[Rule::unique('tenant.products','sku')],'barcode'=>['nullable',Rule::unique('tenant.products','barcode')]])->validate();
                $row=Product::create(['name'=>$data['name'],'sku'=>$sku,'barcode'=>($data['barcode']??null)?:null,'product_group_id'=>$data['product_group_id'],'unit'=>$data['unit'],'sale_price'=>$data['sale_price'],'type'=>$type,'controls_stock'=>$stock,'reference_cost'=>'0.0000','minimum_stock'=>'0.000','active'=>true]);
                app(PricingService::class)->syncProduct($row->id,$actor,'product:quick-create',null);
                if(bccomp($qty,'0',3)>0)app(Inventory::class)->move($row->id,'in',$qty,(string)$data['initial_cost'],'Saldo inicial cadastrado no atendimento',$actor,Uuid::uuid5(Uuid::NAMESPACE_URL,'vendra:quick-stock:'.$key)->toString());
                $row->refresh();
            }
            OperationRecord::create(['version'=>0,'user_id'=>$actor,'action'=>'quick_created','before'=>[],'after'=>['request_key'=>$key,'kind'=>$kind,'hash'=>$hash,'target_id'=>$row->id],'created_at'=>now()]);
            return $this->present($row,$kind);
        },3);
    }
    private function present(Customer|Product $row,string $kind): array
    {
        if($row instanceof Customer)return ['kind'=>$kind,'id'=>$row->id,'name'=>$row->name];
        if(!$row->active)throw ValidationException::withMessages(['name'=>'O item foi desativado. Confira o cadastro antes de usá-lo.']);
        return ['kind'=>$kind,'id'=>$row->id,'name'=>$row->name,'sku'=>$row->sku,'barcode'=>$row->barcode,'type'=>$row->type,
            'price'=>(string)$row->sale_price,'quantity'=>(string)$row->quantity,'controls_stock'=>(bool)$row->controls_stock,
            'discount_enabled'=>$row->discountEnabled(),'discount_limit'=>(string)$row->maxDiscountPercent()];
    }
}
