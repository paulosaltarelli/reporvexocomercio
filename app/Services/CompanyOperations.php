<?php
namespace App\Services;

use App\Models\{BusinessProfile, CompanyOperation, Document, FiscalCredential, FiscalIssuer, Product, User};
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;

/** No singleton service or static cache: every read follows the resolved tenant connection. */
class CompanyOperations
{
    public const DEFAULTS = [
        'catalog_mode'=>'mixed', 'inventory'=>true, 'commissions'=>true, 'commission_basis'=>'cash',
        'discounts'=>true, 'quotes'=>true, 'work_orders'=>true, 'nfce'=>true, 'nfe'=>true, 'nfse'=>true,
    ];
    public function current(): array
    {
        $query=CompanyOperation::whereKey(1);
        if (DB::connection('tenant')->transactionLevel()>0) $query->sharedLock();
        return array_replace(self::DEFAULTS, $query->first()?->configuration ?? []);
    }
    public function lock(): array
    {
        // A current locking read protects settings (or the missing-row gap) without
        // creating a row or opening a repeatable-read snapshot before domain locks.
        // This preserves both request-key races and the shared sale lock of models 55/65.
        $row=CompanyOperation::whereKey(1)->sharedLock()->first();
        return array_replace(self::DEFAULTS, $row?->configuration ?? []);
    }
    public function requireModule(string $key): void
    {
        if (! ($this->current()[$key] ?? false)) {
            throw ValidationException::withMessages(['configuration'=>'Este recurso está desabilitado em Administração → Operação e implantação.']);
        }
    }
    public function assertItem(string $type, ?array $configuration = null): void
    {
        $mode=($configuration ?? $this->current())['catalog_mode'];
        if (! in_array($type, ['product','service'], true) || ($mode==='products' && $type!=='product') || ($mode==='services' && $type!=='service')) {
            throw ValidationException::withMessages(['items'=>'O tipo deste item não está habilitado para a operação da empresa.']);
        }
    }
    public function save(array $input, int $actor): CompanyOperation
    {
        abort_unless(User::whereKey($actor)->where('active',true)->where('role','admin')->exists(),403);
        $rules=['version'=>'required|integer|min:0', 'catalog_mode'=>'required|in:mixed,products,services', 'commission_basis'=>'required|in:cash,accrual'];
        foreach (array_diff(array_keys(self::DEFAULTS), ['catalog_mode','commission_basis']) as $key) $rules[$key]='required|boolean';
        $data=Validator::make($input,$rules)->validate();
        foreach (array_keys(self::DEFAULTS) as $key) if (is_bool(self::DEFAULTS[$key])) $data[$key]=(bool)$data[$key];
        if ($data['catalog_mode']==='services' && ($data['inventory'] || $data['nfce'] || $data['nfe'])) {
            throw ValidationException::withMessages(['catalog_mode'=>'Operação somente de serviços exige estoque, NFC-e e NF-e desabilitados.']);
        }
        if ($data['catalog_mode']==='products' && ($data['nfse'] || $data['work_orders'])) {
            throw ValidationException::withMessages(['catalog_mode'=>'Para NFS-e ou ordens de serviço, habilite também serviços.']);
        }
        return DB::connection('tenant')->transaction(function () use ($data,$actor) {
            CompanyOperation::firstOrCreate(['id'=>1],['configuration'=>self::DEFAULTS]);
            $row=CompanyOperation::whereKey(1)->lockForUpdate()->firstOrFail();
            if ($row->version!==(int)$data['version']) throw ValidationException::withMessages(['version'=>'A configuração mudou. Recarregue e confira antes de salvar.']);
            $before=array_replace(self::DEFAULTS,$row->configuration);
            if ($before['inventory']!==$data['inventory'] && Product::where('quantity','<>',0)->sharedLock()->first()) {
                throw ValidationException::withMessages(['inventory'=>'Concilie e zere os saldos por movimentações identificadas antes de mudar o controle de estoque.']);
            }
            if ($before['catalog_mode']!==$data['catalog_mode'] && $data['catalog_mode']!=='mixed') {
                $excluded=$data['catalog_mode']==='services'?'product':'service';
                if (Product::where('type',$excluded)->where('active',true)->sharedLock()->first() || ($excluded==='service' && \App\Models\Service::where('active',true)->whereNotIn('id',Product::whereNotNull('legacy_service_id')->select('legacy_service_id'))->exists())) {
                    throw ValidationException::withMessages(['catalog_mode'=>'Desative os itens incompatíveis antes de restringir a operação. O histórico será preservado.']);
                }
                if (Document::whereNull('sale_id')->whereNotIn('status',['cancelled','invoiced'])->sharedLock()->first()) {
                    throw ValidationException::withMessages(['catalog_mode'=>'Conclua ou cancele os documentos pendentes antes de restringir os tipos de operação.']);
                }
            }
            foreach (['quotes'=>'quote','work_orders'=>'work_order'] as $key=>$type) {
                if ($before[$key] && ! $data[$key] && Document::where('type',$type)->whereNull('sale_id')->whereNotIn('status',['cancelled','invoiced'])->sharedLock()->first()) {
                    throw ValidationException::withMessages([$key=>'Existem documentos pendentes deste tipo. Conclua ou cancele antes de desabilitar.']);
                }
            }
            unset($data['version']);
            $data=array_replace(self::DEFAULTS,$data);
            if ($before===$data) return $row;
            $row->update(['configuration'=>$data,'version'=>$row->version+1,'updated_by'=>$actor,'completed_at'=>null]);
            $this->audit($row,$actor,'configuration',$before,$data);
            return $row;
        },3);
    }
    public function pending(): array
    {
        $c=$this->current(); $p=BusinessProfile::find(1); $pending=[];
        if (! $p?->legal_name) $pending[]='Preencha a razão social em Minha empresa.';
        if (! \App\Fiscal\TaxpayerDocument::cnpj((string)$p?->document)) $pending[]='Preencha o CNPJ da empresa.';
        if ($c['nfce'] || $c['nfe'] || $c['nfse']) {
            foreach (['street','number','district','city','state','postal_code','municipality_code','tax_regime'] as $field) {
                if (empty($p?->$field)) { $pending[]='Complete endereço, código IBGE e regime tributário em Minha empresa.'; break; }
            }
            $issuer=FiscalIssuer::where('document',\App\Fiscal\TaxpayerDocument::normalize((string)$p?->document))->where('environment','homologation')->first();
            if (($c['nfce'] || $c['nfe']) && (! $issuer || ! FiscalCredential::where('issuer_id',$issuer->id)->where('valid_from','<=',now())->where('valid_until','>',now())->where('certificate_document',\App\Fiscal\TaxpayerDocument::normalize((string)$p?->document))->exists())) {
                $pending[]='Vincule e valide um certificado da empresa nas configurações fiscais.';
            }
            if ($c['nfse'] && ! \App\Models\NfseConfiguration::where('cnpj',\App\Fiscal\TaxpayerDocument::normalize((string)$p?->document))->where('certificate_valid_until','>',now())->exists()) $pending[]='Vincule o certificado na preparação de NFS-e da empresa.';
        }
        return $pending;
    }
    public function complete(int $version, int $actor): void
    {
        abort_unless(User::whereKey($actor)->where('active',true)->where('role','admin')->exists(),403);
        DB::connection('tenant')->transaction(function () use ($version,$actor) {
            CompanyOperation::firstOrCreate(['id'=>1],['configuration'=>self::DEFAULTS]);
            $row=CompanyOperation::whereKey(1)->lockForUpdate()->firstOrFail();
            if ($row->version!==$version) throw ValidationException::withMessages(['version'=>'A configuração mudou. Revise a implantação novamente.']);
            $pending=$this->pending();
            if ($pending) throw ValidationException::withMessages(['configuration'=>array_values($pending)]);
            if ($row->completed_at) return;
            $row->update(['completed_at'=>now(),'updated_by'=>$actor,'version'=>$row->version+1]);
            $this->audit($row,$actor,'completed',[],['completed_at'=>$row->completed_at->toIso8601String()]);
        },3);
    }
    public function invalidateCompany(int $actor): void
    {
        $this->lock();
        $row=CompanyOperation::whereKey(1)->lockForUpdate()->first();
        if (! $row?->completed_at) return;
        $row->update(['completed_at'=>null,'updated_by'=>$actor,'version'=>$row->version+1]);
        $this->audit($row,$actor,'company_changed',[],[]);
    }
    private function audit(CompanyOperation $row,int $actor,string $action,array $before,array $after): void
    {
        DB::connection('tenant')->table('company_operation_audits')->insert(['version'=>$row->version,'user_id'=>$actor,'action'=>$action,'before'=>json_encode($before,JSON_THROW_ON_ERROR),'after'=>json_encode($after,JSON_THROW_ON_ERROR),'created_at'=>now()]);
    }
}
