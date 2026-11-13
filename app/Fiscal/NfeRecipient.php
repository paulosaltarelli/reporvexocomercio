<?php
namespace App\Fiscal;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class NfeRecipient
{
    private const UF = ['AC'=>'12','AL'=>'27','AP'=>'16','AM'=>'13','BA'=>'29','CE'=>'23','DF'=>'53','ES'=>'32','GO'=>'52','MA'=>'21','MT'=>'51','MS'=>'50','MG'=>'31','PA'=>'15','PB'=>'25','PR'=>'41','PE'=>'26','PI'=>'22','RJ'=>'33','RN'=>'24','RS'=>'43','RO'=>'11','RR'=>'14','SC'=>'42','SP'=>'35','SE'=>'28','TO'=>'17'];
    public function save(Tenant $tenant, int $customerId, array $data, int $userId): Customer
    {
        abort_unless($tenant->active && DB::connection('tenant')->getDatabaseName() === $tenant->db_name && User::whereKey($userId)->where('active', true)->where('role', 'admin')->exists(), 403);
        return DB::connection('tenant')->transaction(function () use ($customerId, $data) {
            $customer = Customer::whereKey($customerId)->lockForUpdate()->firstOrFail();
            $valid = $this->validate($data, (string) $customer->document);
            $customer->update($valid);
            return $customer;
        }, 3);
    }
    public function snapshot(Customer $customer, string $issuerState): array
    {
        $fields = ['fiscal_street','fiscal_number','fiscal_complement','fiscal_district','fiscal_city','fiscal_state','fiscal_postal_code','fiscal_municipality_code','fiscal_ie_indicator','fiscal_state_registration'];
        $valid = $this->validate($customer->only($fields), (string) $customer->document);
        if ($valid['fiscal_state'] !== $issuerState) { throw ValidationException::withMessages(['customer_id' => 'Esta versão da NF-e atende somente destinatário na mesma UF do emitente.']); }
        return $valid + ['customer_id' => $customer->id, 'document' => TaxpayerDocument::normalize((string) $customer->document), 'name' => $customer->name];
    }
    public static function stateCode(string $state): ?string { return self::UF[$state] ?? null; }
    public function validate(array $data, string $document): array
    {
        $document = TaxpayerDocument::normalize($document);
        if (! TaxpayerDocument::cpf($document) && (! preg_match('/^[0-9]{14}$/D', $document) || ! TaxpayerDocument::cnpj($document))) {
            throw ValidationException::withMessages(['customer_id' => 'Complete o CPF/CNPJ válido no cadastro do cliente.']);
        }
        foreach ($data as $key => $value) { if (is_string($value)) { $data[$key] = trim($value); } }
        $data['fiscal_ie_indicator'] = (string) ($data['fiscal_ie_indicator'] ?? '');
        $data['fiscal_state'] = strtoupper((string) ($data['fiscal_state'] ?? ''));
        $valid = Validator::make($data, [
            'fiscal_street'=>'required|string|max:60','fiscal_number'=>'required|string|max:60','fiscal_complement'=>'nullable|string|max:60',
            'fiscal_district'=>'required|string|max:60','fiscal_city'=>'required|string|max:60','fiscal_state'=>'required|in:'.implode(',', array_keys(self::UF)),
            'fiscal_postal_code'=>'required|digits:8','fiscal_municipality_code'=>'required|digits:7','fiscal_ie_indicator'=>'required|in:1,9',
            'fiscal_state_registration'=>'nullable|required_if:fiscal_ie_indicator,1|regex:/^[0-9]{2,14}$/D',
        ])->validate();
        if (! str_starts_with($valid['fiscal_municipality_code'], self::UF[$valid['fiscal_state']])) {
            throw ValidationException::withMessages(['fiscal_municipality_code'=>'O código IBGE do destinatário deve corresponder à UF informada.']);
        }
        if (strlen($document) === 11 && $valid['fiscal_ie_indicator'] !== '9') { throw ValidationException::withMessages(['fiscal_ie_indicator'=>'Este fluxo trata destinatário CPF como não contribuinte.']); }
        if ($valid['fiscal_ie_indicator'] === '9') { $valid['fiscal_state_registration'] = null; }
        return $valid;
    }
}
