<?php

namespace App\Fiscal;

use App\Services\Decimal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class TaxProfile
{
    public const REGULAR_RESALE_2027 = 'regular_resale_2027';

    public function validate(array $data): array
    {
        $rules = [
            'tax_model' => 'sometimes|required|in:legacy,'.self::REGULAR_RESALE_2027,
            'valid_from' => 'nullable|date_format:Y-m-d',
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'cfop' => 'required|in:5102',
            'gtin' => ['required', 'regex:/^(?:SEM GTIN|[0-9]{8}|[0-9]{12,14})$/'],
            'cest' => 'nullable|digits:7',
        ];
        if (!empty($data['valid_from'])) {
            $rules['valid_until'][] = 'after_or_equal:valid_from';
        }
        if (($data['tax_model'] ?? null) === self::REGULAR_RESALE_2027) {
            $rules['valid_from'] = 'required|date_format:Y-m-d|after_or_equal:2027-01-01|before_or_equal:2027-12-31';
            $rules['valid_until'][] = 'before_or_equal:2027-12-31';
            $rules += [
                'icms_code' => 'required|in:00',
                'icms_rate' => 'required|numeric|gt:0|max:100|decimal:0,4',
                'rtc_mode' => 'required|in:standard',
                'rtc_cst' => 'required|in:000',
                'rtc_class' => 'required|in:000001',
                'ibs_uf_rate' => 'required|numeric|min:0.05|max:0.05|decimal:0,4',
                'ibs_municipal_rate' => 'required|numeric|min:0.05|max:0.05|decimal:0,4',
                'cbs_rate' => 'required|numeric|gt:0|max:100|decimal:0,4',
            ];
        } else {
            $rules += [
                'icms_code' => 'required|in:00,40,41,50,102,103,300,400',
                'icms_rate' => 'nullable|required_if:icms_code,00|numeric|min:0|max:100|decimal:0,4',
                'pis_code' => 'required|in:01,02,04,06,07,08,09', 'pis_rate' => 'nullable|required_if:pis_code,01,02|numeric|min:0|max:100|decimal:0,4',
                'cofins_code' => 'required|in:01,02,04,06,07,08,09', 'cofins_rate' => 'nullable|required_if:cofins_code,01,02|numeric|min:0|max:100|decimal:0,4',
                'rtc_mode' => 'required|in:standard,not_applicable', 'rtc_reason' => 'nullable|required_if:rtc_mode,not_applicable|string|max:255',
                'rtc_cst' => 'nullable|required_if:rtc_mode,standard|in:000', 'rtc_class' => 'nullable|required_if:rtc_mode,standard|in:000001',
                'ibs_uf_rate' => 'nullable|required_if:rtc_mode,standard|numeric|min:0|max:100|decimal:0,4',
                'ibs_municipal_rate' => 'nullable|required_if:rtc_mode,standard|numeric|min:0|max:100|decimal:0,4',
                'cbs_rate' => 'nullable|required_if:rtc_mode,standard|numeric|min:0|max:100|decimal:0,4',
                'pis_cofins_base_mode' => 'nullable|in:gross,exclude_icms',
                'transparency_rate' => 'required|numeric|min:0|max:100|decimal:0,4',
            ];
        }
        $validated = Validator::make($data, $rules)->validate();
        if (($validated['tax_model'] ?? null) === self::REGULAR_RESALE_2027) {
            $validated['valid_until'] ??= '2027-12-31';
        }
        if ($validated['gtin'] !== 'SEM GTIN') {
            $gtin = $validated['gtin'];
            $sum = 0;
            $weight = 3;
            for ($i = strlen($gtin) - 2; $i >= 0; $i--) {
                $sum += (int) $gtin[$i] * $weight;
                $weight = $weight === 3 ? 1 : 3;
            }
            if (preg_match('/^0+$/D', $gtin) || (int) substr($gtin, -1) !== (10 - $sum % 10) % 10) {
                throw ValidationException::withMessages(['gtin' => 'Dígito verificador do GTIN inválido.']);
            }
        }

        return $validated;
    }

    /** Reusable taxation only: identity/classification belongs to each product. */
    public function validateShared(array $data): array
    {
        Validator::make($data, ['gtin' => 'prohibited', 'cest' => 'prohibited', 'ncm' => 'prohibited', 'origin' => 'prohibited', 'unit' => 'prohibited'])->validate();
        $validated = $this->validate($data + ['gtin' => 'SEM GTIN']);
        unset($validated['gtin'], $validated['cest']);
        return $validated;
    }

    public function fields(array $rule, string $gross, string $crt): array
    {
        $r = $this->validate($rule);
        if (($r['tax_model'] ?? null) === self::REGULAR_RESALE_2027) {
            throw ValidationException::withMessages(['tax_model' => 'A regra de revenda de 2027 está disponível para prévia de cálculo. A emissão de XML com esta regra ainda está em desenvolvimento.']);
        }
        $gross = Decimal::input($gross, 2, 'tax', '0.01');
        if ($r['rtc_mode'] !== 'standard') {
            throw ValidationException::withMessages(['rtc_mode' => 'A emissão direta inicial exige IBS/CBS integral validado (CST 000/cClassTrib 000001). A dispensa ou outro tratamento ainda não tem cálculo implementado; não use justificativa livre para omitir grupo obrigatório.']);
        }
        $taxedPis = in_array($r['pis_code'], ['01', '02'], true);
        $taxedCofins = in_array($r['cofins_code'], ['01', '02'], true);
        if ($r['icms_code'] === '00' && ($taxedPis || $taxedCofins)
            && ($r['pis_cofins_base_mode'] ?? null) !== 'exclude_icms') {
            throw ValidationException::withMessages(['pis_cofins_base_mode' => 'Para este perfil com ICMS destacado e PIS/COFINS tributados, confirme explicitamente a exclusão do ICMS da base. Outra composição de base exige cálculo específico antes da emissão.']);
        }
        if ((strlen($r['icms_code']) === 3) !== in_array($crt, ['1', '4'], true)) {
            throw ValidationException::withMessages(['tax' => 'CST/CSOSN incompatível com o CRT configurado.']);
        }
        $fields = ['cfop' => $r['cfop'], 'icms_situacao_tributaria' => $r['icms_code'], 'pis_situacao_tributaria' => $r['pis_code'], 'cofins_situacao_tributaria' => $r['cofins_code'], 'codigo_barras_comercial' => $r['gtin'], 'codigo_barras_tributavel' => $r['gtin'], 'valor_total_tributos' => $this->percentage($gross, $r['transparency_rate'])];
        if (! empty($r['cest'])) {
            $fields['codigo_cest'] = $r['cest'];
        }
        foreach (['icms' => 'icms_code', 'pis' => 'pis_code', 'cofins' => 'cofins_code'] as $prefix => $code) {
            if (($prefix === 'icms' && $r[$code] === '00') || ($prefix !== 'icms' && in_array($r[$code], ['01', '02'], true))) {
                $base = $gross;
                if ($prefix !== 'icms' && ($r['pis_cofins_base_mode'] ?? null) === 'exclude_icms') {
                    $base = bcsub($gross, $fields['icms_valor'] ?? '0.00', 2);
                    if (bccomp($base, '0', 2) < 0) {
                        throw ValidationException::withMessages(['pis_cofins_base_mode' => 'A exclusão do ICMS produz base negativa; revise os parâmetros.']);
                    }
                }
                $fields[$prefix.'_base_calculo'] = $base;
                $fields[$prefix.'_aliquota'] = $r[$prefix.'_rate'];
                $fields[$prefix.'_valor'] = $this->percentage($base, $r[$prefix.'_rate']);
                if ($prefix === 'icms') {
                    $fields['icms_modalidade_base_calculo'] = '3';
                }
            }
        }
        if ($r['rtc_mode'] === 'standard') {
            $base = $gross;
            foreach (['icms_valor', 'pis_valor', 'cofins_valor'] as $deduct) {
                $base = bcsub($base, $fields[$deduct] ?? '0.00', 2);
            }
            if (bccomp($base, '0', 2) < 0) {
                throw ValidationException::withMessages(['tax' => 'Base IBS/CBS negativa; revise os parâmetros tributários.']);
            }
            $fields['ibs_cbs_situacao_tributaria'] = $r['rtc_cst'];
            $fields['ibs_cbs_classificacao_tributaria'] = $r['rtc_class'];
            $fields['ibs_cbs_base_calculo'] = $base;
            foreach (['ibs_uf' => 'ibs_uf_rate', 'ibs_mun' => 'ibs_municipal_rate', 'cbs' => 'cbs_rate'] as $prefix => $rate) {
                $fields[$prefix.'_aliquota'] = $r[$rate];
                $fields[$prefix.'_valor'] = $this->percentage($base, $r[$rate]);
            }
            $fields['ibs_valor_total'] = bcadd($fields['ibs_uf_valor'], $fields['ibs_mun_valor'], 2);
        }

        return $fields;
    }

    private function percentage(string $base, string $rate): string
    {
        return Decimal::round(bcdiv(bcmul($base, $rate, 8), '100', 8), 2);
    }
}
