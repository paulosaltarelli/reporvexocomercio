<?php
namespace App\Services;

use App\Models\{CompanyOperation,OperationRecord,User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PaymentMethods
{
    public const TYPES=['cash'=>'Dinheiro','pix'=>'Pix','credit'=>'Cartão de crédito','debit'=>'Cartão de débito','transfer'=>'Transferência','invoice'=>'Boleto / a prazo'];
    public function all(): array
    {
        $defaults=[];foreach(self::TYPES as $key=>$label)$defaults[$key]=['label'=>$label,'type'=>$key,'active'=>true];
        return CompanyOperation::find(1)?->configuration['_payment_methods']??$defaults;
    }
    public function options(): array {return array_filter($this->all(),fn($m)=>$m['active']);}
    public function resolve(?string $id,string $fallback): array
    {
        $id=$id?:$fallback;$method=$this->all()[$id]??null;
        if(!$method||!$method['active']||!isset(self::TYPES[$method['type']]))throw ValidationException::withMessages(['payment_option'=>'Esta forma de pagamento está indisponível. Atualize a tela e selecione uma forma ativa.']);
        return ['id'=>$id]+$method;
    }
    public function request(Request $r): void
    {
        if(!$r->filled('payment_option'))return;
        $r->validate(['payment_option'=>'required|string|max:60']);$method=$this->resolve($r->input('payment_option'),(string)$r->input('payment_method','cash'));$r->merge(['payment_method'=>$method['type']]);
    }
    public function save(array $input,int $actor): void
    {
        abort_unless(\App\Services\InstallerAccess::adminActor($actor),403);
        if(is_string($input['label']??null))$input['label']=trim($input['label']);
        $d=Validator::make($input,['id'=>'nullable|string|max:60','version'=>'required|integer|min:0','label'=>'required|string|min:2|max:80','type'=>'required|in:'.implode(',',array_keys(self::TYPES)),'active'=>'required|boolean'])->validate();
        DB::connection('tenant')->transaction(function()use($d,$actor){
            CompanyOperation::firstOrCreate(['id'=>1],['configuration'=>CompanyOperations::DEFAULTS]);$row=CompanyOperation::whereKey(1)->lockForUpdate()->firstOrFail();
            if($row->version!==(int)$d['version'])throw ValidationException::withMessages(['version'=>'O cadastro mudou. Atualize a página antes de salvar.']);
            $methods=$this->all();$id=$d['id']??(string)Str::uuid();
            if(isset($d['id'])&&!isset($methods[$id]))abort(404);
            if(isset(self::TYPES[$id])&&$d['type']!==$id)throw ValidationException::withMessages(['type'=>'O tipo de uma forma padrão é fixo. Crie uma nova opção para outro tipo.']);
            foreach($methods as $key=>$method)if($key!==$id&&mb_strtolower($method['label'])===mb_strtolower($d['label']))throw ValidationException::withMessages(['label'=>'Já existe uma forma com este nome.']);
            $before=$methods[$id]??[];$after=['label'=>$d['label'],'type'=>$d['type'],'active'=>(bool)$d['active']];$methods[$id]=$after;
            if(!array_filter($methods,fn($m)=>$m['active']))throw ValidationException::withMessages(['active'=>'Mantenha ao menos uma forma de pagamento ativa.']);
            $config=$row->configuration;$config['_payment_methods']=$methods;$row->update(['configuration'=>$config,'version'=>$row->version+1,'updated_by'=>$actor]);
            OperationRecord::create(['version'=>$row->version,'user_id'=>$actor,'action'=>'payment_method','before'=>$before,'after'=>['id'=>$id]+$after,'created_at'=>now()]);
        },3);
    }
    public function snapshot(string $action,int $target,array $method,int $actor): void
    {
        OperationRecord::create(['version'=>0,'user_id'=>$actor,'action'=>$action,'before'=>[],'after'=>['target_id'=>$target,'method_id'=>$method['id'],'label'=>$method['label'],'type'=>$method['type']],'created_at'=>now()]);
    }
    public function labels(string $action,array $ids): array
    {
        return OperationRecord::where('action',$action)->whereIn('after->target_id',$ids)->orderBy('id')->get()->mapWithKeys(fn($r)=>[$r->after['target_id']=>$r->after['label']])->all();
    }
}
