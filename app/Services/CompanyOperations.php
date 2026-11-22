<?php
namespace App\Services;

use App\Models\{BusinessProfile, CompanyOperation, Document, FiscalCredential, FiscalIssuer, Product, User};
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;

/** No singleton service or static cache: every read follows the resolved tenant connection. */
class CompanyOperations
{
    public const DEFAULTS = [
        'weighed_labels'=>WeightedLabels::DEFAULTS,
        'segment'=>'commerce', 'operation_modes'=>['commerce'], 'default_operation'=>'commerce',
        'catalog_mode'=>'mixed', 'inventory'=>true, 'negative_stock'=>'block', 'commissions'=>true, 'commission_basis'=>'cash',
        'discounts'=>true, 'quotes'=>true, 'work_orders'=>true, 'nfce'=>true, 'nfe'=>true, 'nfse'=>false,
    ];
    private function effective(array $stored): array
    {
        $configuration = array_replace(self::DEFAULTS, $stored);
        if (!config('fiscal.product_invoices_enabled', false)) {
            $configuration['nfce'] = false;
            $configuration['nfe'] = false;
        }
        $configuration['nfse'] = false;
        $configuration['operation_modes'] = array_values(array_intersect(
            array_keys(OperationalModes::MODES),
            is_array($configuration['operation_modes']) ? $configuration['operation_modes'] : ['commerce']
        ));
        if (!$configuration['operation_modes']) $configuration['operation_modes'] = ['commerce'];
        if (!in_array($configuration['default_operation'], $configuration['operation_modes'], true)) {
            $configuration['default_operation'] = $configuration['operation_modes'][0];
        }
        if (!isset(OperationalModes::SEGMENTS[$configuration['segment']])) $configuration['segment'] = 'commerce';
        return $configuration;
    }

    public function current(): array
    {
        $query=CompanyOperation::whereKey(1);
        if (DB::connection('tenant')->transactionLevel()>0) $query->sharedLock();
        return $this->effective($query->first()?->configuration ?? []);
    }
    public function lock(): array
    {
        // A current locking read protects settings (or the missing-row gap) without
        // creating a row or opening a repeatable-read snapshot before domain locks.
        // This preserves both request-key races and the shared sale lock of models 55/65.
        $row=CompanyOperation::whereKey(1)->sharedLock()->first();
        return $this->effective($row?->configuration ?? []);
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
        abort_unless(User::whereKey($actor)->where('active',true)->whereIn('role',['admin',User::ROLE_INSTALLER])->exists(),403);
        $rules=['version'=>'required|integer|min:0', 'catalog_mode'=>'required|in:mixed,products,services', 'commission_basis'=>'required|in:cash,accrual', 'negative_stock'=>'sometimes|required|in:block,warn,allow'];
        $defaults = self::DEFAULTS;
        unset($defaults['nfse']);
        $productInvoices = config('fiscal.product_invoices_enabled', false);
        if (!$productInvoices) unset($defaults['nfe'], $defaults['nfce']);
        foreach (array_diff(array_keys($defaults), ['catalog_mode','commission_basis','negative_stock','segment','operation_modes','default_operation','weighed_labels']) as $key) $rules[$key]='required|boolean';
        $rules += [
            'weighed_labels' => 'sometimes|required|array',
            'segment' => 'sometimes|required|in:commerce,restaurant,supermarket',
            'operation_modes' => 'sometimes|required|array|min:1|max:3',
            'operation_modes.*' => 'required|string|distinct|in:commerce,restaurant,supermarket',
            'default_operation' => 'sometimes|required|in:commerce,restaurant,supermarket',
        ];
        if (array_key_exists('weighed_labels', $input)) {
            abort_unless(InstallerAccess::adminActor($actor),403);
        }
        $data=Validator::make($input,$rules)->validate();
        if (array_key_exists('weighed_labels', $data)) $data['weighed_labels'] = WeightedLabels::normalize($data['weighed_labels']);
        foreach (array_keys($defaults) as $key) if (is_bool($defaults[$key])) $data[$key]=(bool)$data[$key];
        if ($data['catalog_mode']==='services' && ($data['inventory'] || ($data['nfce'] ?? false) || ($data['nfe'] ?? false))) {
            throw ValidationException::withMessages(['catalog_mode'=>$productInvoices ? 'Operação somente de serviços exige estoque, NFC-e e NF-e desabilitados.' : 'Desabilite a gestão de estoque para operar somente com serviços.']);
        }
        if ($data['catalog_mode']==='products' && $data['work_orders']) {
            throw ValidationException::withMessages(['catalog_mode'=>'Para ordens de serviço, habilite também serviços.']);
        }
        return DB::connection('tenant')->transaction(function () use ($data,$actor,$defaults) {
            CompanyOperation::firstOrCreate(['id'=>1],['configuration'=>self::DEFAULTS]);
            $row=CompanyOperation::whereKey(1)->lockForUpdate()->firstOrFail();
            InstallerAccess::wizardActor($actor,$row);
            if ($row->version!==(int)$data['version']) throw ValidationException::withMessages(['version'=>'A configuração mudou. Recarregue e confira antes de salvar.']);
            $before=array_intersect_key(array_replace($defaults,$row->configuration),$defaults);
            $data['negative_stock'] ??= $before['negative_stock'];
            $data['weighed_labels'] ??= $before['weighed_labels'];
            $data['segment'] ??= $before['segment'];
            $data['operation_modes'] ??= $before['operation_modes'];
            $data['default_operation'] ??= $before['default_operation'];
            $data['operation_modes'] = array_values(array_intersect(array_keys(OperationalModes::MODES), $data['operation_modes']));
            if (!in_array($data['default_operation'], $data['operation_modes'], true)) {
                throw ValidationException::withMessages(['default_operation'=>'Escolha um atendimento inicial entre os modos habilitados.']);
            }
            $disabledModes = array_diff($before['operation_modes'], $data['operation_modes']);
            if (in_array('restaurant', $disabledModes, true)
                && DB::connection('tenant')->table('restaurant_orders')->where('status','open')->lockForUpdate()->first(['id'])) {
                throw ValidationException::withMessages(['operation_modes'=>'Feche ou cancele as comandas abertas antes de desabilitar o restaurante.']);
            }
            if (in_array('supermarket', $disabledModes, true)
                && DB::connection('tenant')->table('supermarket_shifts')->where('open_slot', true)->lockForUpdate()->first(['id'])) {
                throw ValidationException::withMessages(['operation_modes'=>'Encerre os turnos de caixa antes de desabilitar o supermercado.']);
            }
            if (in_array('supermarket', $disabledModes, true)
                && DB::connection('tenant')->table('supermarket_carts')->whereIn('status',['active','suspended'])->lockForUpdate()->first(['id'])) {
                throw ValidationException::withMessages(['operation_modes'=>'Conclua ou cancele os carrinhos ativos e suspensos antes de desabilitar o supermercado.']);
            }
            if ($before['inventory'] && !$data['inventory'] && \App\Models\RestaurantOrderItem::where('status','<>','cancelled')
                ->whereHas('order',fn($query)=>$query->where('status','open'))
                ->where(fn($query)=>$query->where('commercial_terms->_restaurant_catalog->recipe->mode','recipe')
                    ->orWhereJsonLength('commercial_terms->_restaurant_catalog->recipe->ingredients','>',0))->exists()) {
                throw ValidationException::withMessages(['inventory'=>'Conclua ou cancele as comandas com ingredientes antes de desativar a gestão de estoque.']);
            }
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
            $data=array_replace($defaults,$data);
            if ($before===$data) return $row;
            $configuration=array_replace($row->configuration,$data);
            $row->update(['configuration'=>$configuration,'version'=>$row->version+1,'updated_by'=>$actor,'completed_at'=>null]);
            $this->audit($row,$actor,'configuration',$before,$data);
            return $row;
        },3);
    }
    public function pending(): array
    {
        return array_keys($this->pendingSteps());
    }

    /** @return array<string,int> Blocking message and the setup step that resolves it. */
    public function pendingSteps(): array
    {
        $c=$this->current(); $p=BusinessProfile::find(1); $pending=[];
        if (! $p?->legal_name) $pending['Preencha a razão social em Minha empresa.']=1;
        if (! \App\Fiscal\TaxpayerDocument::cnpj((string)$p?->document)) $pending['Preencha o CNPJ da empresa.']=1;
        if ($c['nfce'] || $c['nfe']) {
            foreach (['street','number','district','city','state','postal_code','municipality_code','tax_regime'] as $field) {
                if (empty($p?->$field)) { $pending['Complete endereço, código IBGE e regime tributário em Minha empresa.']=1; break; }
            }
            $issuer=FiscalIssuer::where('document',\App\Fiscal\TaxpayerDocument::normalize((string)$p?->document))->where('environment','homologation')->first();
            if (! $issuer || ! FiscalCredential::where('issuer_id',$issuer->id)->where('valid_from','<=',now())->where('valid_until','>',now())->where('certificate_document',\App\Fiscal\TaxpayerDocument::normalize((string)$p?->document))->exists()) {
                $pending['Vincule e valide um certificado da empresa nas configurações fiscais.']=3;
            }
        }
        return $pending;
    }
    public function complete(int $version, int $actor): void
    {
        abort_unless(User::whereKey($actor)->where('active',true)->whereIn('role',['admin',User::ROLE_INSTALLER])->exists(),403);
        DB::connection('tenant')->transaction(function () use ($version,$actor) {
            CompanyOperation::firstOrCreate(['id'=>1],['configuration'=>self::DEFAULTS]);
            $row=CompanyOperation::whereKey(1)->lockForUpdate()->firstOrFail();
            $operator = InstallerAccess::wizardActor($actor,$row);
            if ($operator->role===User::ROLE_INSTALLER && !\App\Models\Seller::where('active',true)->exists()) {
                throw ValidationException::withMessages(['team'=>'Cadastre um responsável pelo caixa antes de concluir.']);
            }
            if ($row->version!==$version) throw ValidationException::withMessages(['version'=>'A configuração mudou. Revise a implantação novamente.']);
            $pending=$this->pending();
            if ($pending) throw ValidationException::withMessages(['configuration'=>array_values($pending)]);
            if ($row->completed_at) return;
            $configuration=$row->configuration;$configuration['_onboarding_completed_at']??=now()->toIso8601String();
            $row->update(['configuration'=>$configuration,'completed_at'=>now(),'updated_by'=>$actor,'version'=>$row->version+1]);
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
