<?php

namespace App\Fiscal\Nfse;

use App\Fiscal\CertificateInspector;
use App\Fiscal\TaxpayerDocument;
use App\Models\BusinessProfile;
use App\Models\NfseConfiguration;
use App\Models\Tenant;
use App\Services\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class Configuration
{
    public function draft(Tenant $tenant,array $input,int $actor): void
    {
        Access::admin($tenant,$actor);
        $rules=['municipality_code'=>['nullable','regex:/^[0-9]{7}$/D'],'municipal_registration'=>'nullable|string|max:15','timezone'=>'nullable|timezone','op_simp_nac'=>'nullable|in:1,2,3','reg_ap_trib_sn'=>'nullable|in:1,2,3','reg_esp_trib'=>'nullable|in:0','service_national_code'=>['nullable','regex:/^[0-9]{6}$/D'],'service_municipal_code'=>['nullable','regex:/^[0-9]{3}$/D'],'nbs'=>['nullable','regex:/^[0-9]{9}$/D'],'iss_mode'=>'nullable|in:municipal_parameterized','total_tax_mode'=>'nullable|in:not_informed,simples_percentage','total_tax_percent'=>'nullable|numeric|min:0|max:99.99','series'=>'nullable|integer|min:1|max:49999','next_number'=>'nullable|integer|min:1|max:999999999999999','certificate'=>'prohibited','certificate_password'=>'prohibited','csc'=>'prohibited','environment'=>'prohibited','endpoint'=>'prohibited'];
        $data=Validator::make($input,$rules)->validate();
        $values=array_filter(array_intersect_key($data,$rules),fn($v)=>$v!==null&&$v!=='');
        DB::connection('tenant')->transaction(function()use($values,$actor){
            \App\Models\CompanyOperation::firstOrCreate(['id'=>1],['configuration'=>\App\Services\CompanyOperations::DEFAULTS]);
            $row=\App\Models\CompanyOperation::whereKey(1)->lockForUpdate()->firstOrFail();
            $profile=BusinessProfile::whereKey(1)->sharedLock()->firstOrFail();$cnpj=TaxpayerDocument::normalize((string)$profile->document);
            if(!TaxpayerDocument::cnpj($cnpj))throw ValidationException::withMessages(['document'=>'Salve o CNPJ válido da empresa antes do rascunho.']);
            if(isset($values['municipality_code'])&&$values['municipality_code']!==$profile->municipality_code)throw ValidationException::withMessages(['municipality_code'=>'O município deve corresponder à empresa.']);
            $config=$row->configuration;$before=$config['_nfse_draft']??[];$after=['document'=>$cnpj,'values'=>$values];$config['_nfse_draft']=$after;
            $row->update(['configuration'=>$config,'version'=>$row->version+1,'updated_by'=>$actor]);
            DB::connection('tenant')->table('company_operation_audits')->insert(['version'=>$row->version,'user_id'=>$actor,'action'=>'nfse_draft','before'=>json_encode($before),'after'=>json_encode($after),'created_at'=>now()]);
        },3);
    }
    public function draftValues(): array
    {
        $draft=\App\Models\CompanyOperation::find(1)?->configuration['_nfse_draft']??[];
        return ($draft['document']??null)===TaxpayerDocument::normalize((string)BusinessProfile::find(1)?->document)?($draft['values']??[]):[];
    }
    public function save(Tenant $tenant, #[\SensitiveParameter] array $input, #[\SensitiveParameter] ?string $pfx, int $actor): NfseConfiguration
    {
        Access::admin($tenant, $actor);
        $data = Validator::make($input, [
            'enabled' => 'required|boolean', 'national_issuer_confirmed' => 'required|boolean',
            'municipality_code' => ['required', 'regex:/^[0-9]{7}$/D'],
            'municipal_registration' => 'nullable|string|max:15',
            'timezone' => 'required|timezone',
            'op_simp_nac' => 'required|in:1,2,3', 'reg_ap_trib_sn' => 'nullable|in:1,2,3',
            'reg_esp_trib' => 'required|in:0',
            'service_national_code' => ['required', 'regex:/^[0-9]{6}$/D'],
            'service_municipal_code' => ['nullable', 'regex:/^[0-9]{3}$/D'],
            'nbs' => ['required', 'regex:/^[0-9]{9}$/D'],
            'iss_mode' => 'required|in:municipal_parameterized',
            'total_tax_mode' => 'required|in:not_informed,simples_percentage',
            'total_tax_percent' => 'nullable|numeric|min:0|max:99.99',
            'series' => 'required|integer|min:1|max:49999',
            'next_number' => 'required|integer|min:1|max:999999999999999',
            'certificate_password' => 'nullable|string|max:1024',
            'environment' => 'prohibited', 'csc' => 'prohibited', 'endpoint' => 'prohibited',
        ])->validate();
        if ((string) $data['op_simp_nac'] === '3' && empty($data['reg_ap_trib_sn'])) {
            throw ValidationException::withMessages(['reg_ap_trib_sn' => 'Informe o regime de apuração do Simples Nacional.']);
        }
        if ((string) $data['op_simp_nac'] !== '3' && ! empty($data['reg_ap_trib_sn'])) {
            throw ValidationException::withMessages(['reg_ap_trib_sn' => 'Regime de apuração SN somente para ME/EPP optante.']);
        }
        if ($data['total_tax_mode'] === 'simples_percentage' && ((string) $data['op_simp_nac'] !== '3' || ! isset($data['total_tax_percent']))) {
            throw ValidationException::withMessages(['total_tax_percent' => 'Informe o percentual aproximado confirmado do Simples Nacional para ME/EPP.']);
        }
        if ($data['total_tax_mode'] === 'not_informed') {
            $data['total_tax_percent'] = null;
        }
        if ($data['total_tax_mode'] === 'simples_percentage') {
            $data['total_tax_percent'] = Decimal::input((string) $data['total_tax_percent'], 2, 'total_tax_percent', '0', '99.99');
        }
        unset($data['certificate_password']);

        return DB::connection('tenant')->transaction(function () use ($tenant, $input, $pfx, $actor, $data) {
            $profile = BusinessProfile::whereKey(1)->lockForUpdate()->firstOrFail();
            $cnpj = TaxpayerDocument::normalize((string) $profile->document);
            if (! preg_match('/^[0-9]{14}$/D', $cnpj) || ! TaxpayerDocument::cnpj($cnpj)) {
                throw ValidationException::withMessages(['document' => 'Cadastre o CNPJ válido da empresa em Minha empresa.']);
            }
            if ((string) $profile->municipality_code !== $data['municipality_code']) {
                throw ValidationException::withMessages(['municipality_code' => 'Município deve corresponder ao cadastro da empresa.']);
            }
            $expected = ['mei' => '2', 'simples' => '3', 'presumido' => '1', 'real' => '1'];
            if (($expected[$profile->tax_regime] ?? null) !== (string) $data['op_simp_nac']) {
                throw ValidationException::withMessages(['op_simp_nac' => 'Confirme o regime tributário em Minha empresa e sua correspondência com a opção SN.']);
            }
            $configuration = NfseConfiguration::where('cnpj', $cnpj)->lockForUpdate()->first() ?? new NfseConfiguration(['cnpj' => $cnpj]);
            $secrets = $configuration->secrets ?? [];
            $metadata = [];
            if ($pfx !== null) {
                $metadata = app(CertificateInspector::class)->inspect($pfx, (string) ($input['certificate_password'] ?? ''), $cnpj);
                $secrets = ['pfx' => base64_encode($pfx), 'certificate_password' => (string) ($input['certificate_password'] ?? '')];
            }
            $enabled = (bool) $data['enabled'];
            if ($enabled && (empty($secrets['pfx']) || ! (bool) $data['national_issuer_confirmed'])) {
                throw ValidationException::withMessages(['certificate' => 'Para preparar DPS, envie A1 válido e confirme habilitação no Emissor Nacional.']);
            }
            if ($enabled) {
                app(CertificateInspector::class)->inspect(base64_decode($secrets['pfx'], true) ?: '', $secrets['certificate_password'] ?? '', $cnpj);
            }
            $parameters = $data;
            unset($parameters['enabled'], $parameters['national_issuer_confirmed'], $parameters['next_number']);
            $configuration->fill(['environment' => 'restricted', 'enabled' => $enabled, 'national_issuer_confirmed' => (bool) $data['national_issuer_confirmed'], 'parameters' => $parameters, 'secrets' => $secrets, 'updated_by' => $actor]);
            if ($metadata) {
                $configuration->certificate_fingerprint = $metadata['certificate_fingerprint'];
                $configuration->certificate_valid_until = $metadata['valid_until'];
            }
            $configuration->save();
            $sequence = DB::connection('tenant')->table('nfse_sequences')->where('configuration_id', $configuration->id)->where('series', $data['series'])->lockForUpdate()->first();
            if ($sequence && (string) $sequence->next_number !== (string) $data['next_number']) {
                throw ValidationException::withMessages(['next_number' => 'Sequência existente não pode ser reiniciada; informe a próxima numeração atual.']);
            }
            if (! $sequence) {
                DB::connection('tenant')->table('nfse_sequences')->insert(['configuration_id' => $configuration->id, 'series' => $data['series'], 'next_number' => $data['next_number']]);
            }
            return $configuration;
        }, 3);
    }
}
