<?php

namespace App\Fiscal;

use App\Models\BusinessProfile;
use App\Models\FiscalTaxProfile;
use App\Models\Product;
use App\Services\Decimal;
use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class StandardResalePreview
{
    /** One internal resale item in 2027, before IBS/CBS and with ICMS already included. */
    public function calculate(Product $product, array $input): array
    {
        $data = Validator::make($input, [
            'reference_date' => 'required|date_format:Y-m-d|after_or_equal:2027-01-01|before_or_equal:2027-12-31',
            'item_amount' => 'required|numeric',
            'icms_base' => 'required|numeric',
            'confirm_scope' => 'accepted',
            'profile_id' => 'nullable|integer|min:1',
        ])->validate();
        $amount = Decimal::input((string) $data['item_amount'], 2, 'item_amount', '0.01');
        $icmsBase = Decimal::input((string) $data['icms_base'], 2, 'icms_base', $amount);
        $date = new DateTimeImmutable($data['reference_date']);
        $taxRegime = BusinessProfile::whereKey(1)->value('tax_regime');
        if (!in_array($taxRegime, ['real', 'presumido'], true)) {
            throw ValidationException::withMessages(['profile' => 'Esta prévia atende empresas no lucro real ou presumido. Confira o regime em Minha empresa.']);
        }
        $resolver = app(FiscalRuleResolver::class);
        if (!empty($data['profile_id'])) {
            $profile = FiscalTaxProfile::find($data['profile_id']);
            if (!$profile || !$profile->active || ($profile->parameters['tax_model'] ?? null) !== TaxProfile::REGULAR_RESALE_2027) {
                throw ValidationException::withMessages(['profile_id' => 'Selecione um perfil de revenda de 2027 ativo nesta empresa.']);
            }
            $rule = $resolver->resolveForPreview($product, $profile, $date);
        } else {
            $rule = $resolver->resolve($product, referenceDate: $date);
        }
        if (empty($rule['available'])) {
            throw ValidationException::withMessages(['profile' => $resolver->explain($rule)]);
        }
        $parameters = $rule['parameters'];
        if (($parameters['tax_model'] ?? null) !== TaxProfile::REGULAR_RESALE_2027) {
            throw ValidationException::withMessages(['profile' => 'Associe o modelo de revenda regular de 2027 com as alíquotas e a vigência conferidas pelo contador.']);
        }

        // The accountant supplies the full ICMS basis applicable in the issuing state.
        $icms = $this->percentage($icmsBase, $parameters['icms_rate']);
        if (bccomp($icms, $amount, 2) > 0) {
            throw ValidationException::withMessages(['icms_base' => 'O ICMS apurado supera o valor do item antes de IBS e CBS. Confira a base integral do ICMS e a alíquota do perfil.']);
        }
        // LC 214/2025, art. 12, § 2º, and arts. 344/347. Round each jurisdiction per item.
        $base = bcsub($amount, $icms, 2);
        $tax = [];
        $total = $amount;
        foreach (['ibs_uf_rate', 'ibs_municipal_rate', 'cbs_rate'] as $rateField) {
            $rate = $parameters[$rateField];
            $value = $this->percentage($base, $rate);
            $tax[$rateField] = ['rate' => $rate, 'value' => $value];
            $total = bcadd($total, $value, 2);
        }
        if (bccomp($icmsBase, $total, 2) > 0) {
            throw ValidationException::withMessages(['icms_base' => 'A base do ICMS supera o total do item com IBS e CBS. Confira se a operação se enquadra nesta prévia de revenda sem acréscimos.']);
        }

        return [
            'reference_date' => $date->format('d/m/Y'),
            'rule_name' => $rule['profile_name'] ?? 'Exceção individual',
            'accountant_reference' => $rule['accountant_reference'],
            'item_amount' => $amount,
            'icms_amount' => $icms,
            'icms_base' => $icmsBase,
            'icms_rate' => $parameters['icms_rate'],
            'base' => $base,
            'tax' => $tax,
            'total' => $total,
        ];
    }

    private function percentage(string $base, string $rate): string
    {
        return Decimal::round(bcdiv(bcmul($base, $rate, 6), '100', 8));
    }
}
