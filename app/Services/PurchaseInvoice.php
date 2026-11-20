<?php

namespace App\Services;

use App\Models\CompanyOperation;
use App\Models\OperationRecord;
use App\Models\Supplier;
use Illuminate\Validation\ValidationException;

final class PurchaseInvoice
{
    public const TAXES = ['icms' => 'ICMS destacado', 'icms_st' => 'ICMS-ST', 'ipi' => 'IPI', 'pis' => 'PIS', 'cofins' => 'COFINS'];

    public const CHARGES = ['freight' => 'Frete', 'insurance' => 'Seguro', 'expenses' => 'Outras despesas', 'discount' => 'Desconto'];

    public const TREATMENTS = ['cost' => 'Custo de aquisição', 'credit' => 'Crédito recuperável', 'expense' => 'Despesa do período'];

    public static function rules(): array
    {
        $money = 'nullable|numeric|min:0|max:999999999999.99|decimal:0,2';

        return [
            'invoice' => 'sometimes|array:number,series,access_key,issued_on,declared_total,freight,insurance,expenses,discount',
            'invoice.number' => 'nullable|digits_between:1,9',
            'invoice.series' => 'nullable|digits_between:1,3',
            'invoice.access_key' => 'nullable|string|size:44',
            'invoice.issued_on' => 'nullable|date_format:Y-m-d|before_or_equal:purchased_on',
            'invoice.declared_total' => $money,
            ...array_fill_keys(array_map(fn ($key) => 'invoice.'.$key, array_keys(self::CHARGES)), $money),
            'items.*.adjustments' => 'nullable|array:freight,insurance,expenses,discount',
            'items.*.adjustments.*' => $money,
            'items.*.taxes' => 'nullable|array:'.implode(',', array_keys(self::TAXES)),
            'items.*.taxes.*' => 'array:amount,composition,treatment',
            'items.*.taxes.*.amount' => $money,
            'items.*.taxes.*.composition' => 'nullable|in:included,added',
            'items.*.taxes.*.treatment' => 'nullable|in:cost,credit,expense',
        ];
    }

    public static function hasInvoice(array $data): bool
    {
        foreach ($data['invoice'] ?? [] as $field => $value) {
            if ($value !== null && $value !== '' && (! isset(self::CHARGES[$field]) || bccomp((string) $value, '0', 2) !== 0)) {
                return true;
            }
        }
        foreach ($data['items'] as $item) {
            foreach ($item['adjustments'] ?? [] as $value) {
                if (bccomp((string) ($value ?? '0'), '0', 2) > 0) {
                    return true;
                }
            }
            foreach ($item['taxes'] ?? [] as $tax) {
                if (bccomp((string) (($tax['amount'] ?? '') ?: '0'), '0', 2) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function lockRegister(): void
    {
        CompanyOperation::insertOrIgnore(['id' => 1, 'configuration' => json_encode(CompanyOperations::DEFAULTS), 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        CompanyOperation::whereKey(1)->lockForUpdate()->firstOrFail();
    }

    public static function identity(array $header, Supplier $supplier, string $received): array
    {
        $number = (string) ($header['number'] ?? '');
        $series = (string) ($header['series'] ?? '');
        if (! preg_match('/^[0-9]{1,9}$/D', $number) || (int) $number < 1 || ! preg_match('/^[0-9]{1,3}$/D', $series)) {
            throw ValidationException::withMessages(['invoice.number' => 'Informe o número e a série da nota.']);
        }
        $issued = FinancialService::date((string) ($header['issued_on'] ?? ''), 'invoice.issued_on')->toDateString();
        if ($issued > $received) {
            throw ValidationException::withMessages(['invoice.issued_on' => 'A emissão deve anteceder ou coincidir com o recebimento.']);
        }
        $key = strtoupper((string) ($header['access_key'] ?? ''));
        $document = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', (string) $supplier->document));
        if ($key !== '') {
            self::validateKey($key, $document, $series, $number, $issued);
        }
        $issuer = $document !== '' ? $document : 'supplier-'.$supplier->id;
        $identity = $issuer.'-'.(int) $series.'-'.(int) $number;
        if (OperationRecord::where('action', 'purchase_invoice')->where('after->identity', $identity)->sharedLock()->first()) {
            throw ValidationException::withMessages(['invoice.number' => 'Esta nota já foi recebida para o fornecedor e série informados.']);
        }

        return ['identity' => $identity, 'number' => $number, 'series' => $series, 'issued_on' => $issued, 'access_key' => $key, 'supplier_name' => $supplier->name, 'supplier_document' => $supplier->document];
    }

    public static function validateKey(string $key, string $document, string $series, string $number, string $issued): void
    {
        if (! preg_match('/^[0-9]{6}[0-9A-Z]{12}[0-9]{26}$/D', $key) || substr($key, 20, 2) !== '55'
            || substr($key, 6, 14) !== $document || (int) substr($key, 22, 3) !== (int) $series
            || (int) substr($key, 25, 9) !== (int) $number || substr($key, 2, 4) !== substr($issued, 2, 2).substr($issued, 5, 2)) {
            throw ValidationException::withMessages(['invoice.access_key' => 'Confira a chave NF-e, o documento do fornecedor, a data de emissão, o número e a série.']);
        }
        $sum = 0;
        for ($i = 42; $i >= 0; $i--) {
            $sum += (ord($key[$i]) - 48) * (2 + (42 - $i) % 8);
        }
        $digit = 11 - ($sum % 11);
        if ((int) $key[43] !== ($digit >= 10 ? 0 : $digit)) {
            throw ValidationException::withMessages(['invoice.access_key' => 'Dígito verificador da chave inválido.']);
        }
    }

    /** Allocate header values proportionally to gross line amounts using cumulative cents. */
    public static function calculate(array $items, array $header): array
    {
        $gross = '0.00';
        $lines = [];
        foreach ($items as $index => $item) {
            $quantity = Decimal::input((string) $item['quantity'], 3, 'items.quantity', '0.001', '999999999');
            $price = Decimal::input((string) $item['unit_cost'], 4, 'items.unit_cost', '0', '999999999.9999');
            $amount = Decimal::input(Decimal::round(bcmul($quantity, $price, 7)), 2, 'items');
            $lines[$index] = ['gross' => $amount];
            $gross = bcadd($gross, $amount, 2);
        }
        $charges = [];
        foreach (self::CHARGES as $field => $label) {
            $charges[$field] = Decimal::input((string) ($header[$field] ?? '0'), 2, 'invoice.'.$field);
        }
        if (bccomp($charges['discount'], $gross, 2) > 0 || (bccomp($gross, '0', 2) === 0 && array_filter($charges, fn ($v) => bccomp($v, '0', 2) > 0))) {
            throw ValidationException::withMessages(['invoice.discount' => 'Confira o desconto e a base de produtos para o rateio.']);
        }
        $cumulative = '0.00';
        $allocated = array_fill_keys(array_keys($charges), '0.00');
        $totals = array_fill_keys(['payable', 'acquisition', 'credit', 'expense'], '0.00');
        foreach ($lines as $index => &$line) {
            $cumulative = bcadd($cumulative, $line['gross'], 2);
            $payable = $line['gross'];
            foreach ($charges as $field => $value) {
                // Truncate once at the cent boundary; the final cumulative share equals the header.
                $share = bccomp($gross, '0', 2) === 0 ? '0.00' : bcdiv(bcmul($value, $cumulative, 4), $gross, 2);
                $part = bcsub($share, $allocated[$field], 2);
                $allocated[$field] = $share;
                $direct = Decimal::input((string) ($items[$index]['adjustments'][$field] ?? '0'), 2, 'items.'.$index.'.adjustments.'.$field);
                $line['allocated'][$field] = $part;
                $line['direct'][$field] = $direct;
                $part = bcadd($part, $direct, 2);
                $line[$field] = $part;
                $payable = $field === 'discount' ? bcsub($payable, $part, 2) : bcadd($payable, $part, 2);
            }
            if (bccomp($line['discount'], $line['gross'], 2) > 0) {
                throw ValidationException::withMessages(['items.'.$index.'.adjustments.discount' => 'O desconto total do item excede seu valor bruto.']);
            }
            $line['taxes'] = [];
            $line['credit'] = $line['expense'] = '0.00';
            foreach (self::TAXES as $code => $label) {
                $tax = $items[$index]['taxes'][$code] ?? [];
                $amount = Decimal::input((string) ($tax['amount'] ?? '0'), 2, 'items.'.$index.'.taxes.'.$code);
                if (bccomp($amount, '0', 2) === 0) {
                    continue;
                }
                $composition = $tax['composition'] ?? null;
                $treatment = $tax['treatment'] ?? null;
                if (! in_array($composition, ['included', 'added'], true) || ! isset(self::TREATMENTS[$treatment])) {
                    throw ValidationException::withMessages(['items.'.$index.'.taxes.'.$code => 'Informe composição e tratamento de '.$label.'.']);
                }
                if ($composition === 'added') {
                    $payable = bcadd($payable, $amount, 2);
                }
                if ($treatment !== 'cost') {
                    $line[$treatment] = bcadd($line[$treatment], $amount, 2);
                }
                $line['taxes'][$code] = compact('amount', 'composition', 'treatment');
            }
            $line['payable'] = Decimal::input($payable, 2, 'items');
            $line['acquisition'] = Decimal::input(bcsub(bcsub($payable, $line['credit'], 2), $line['expense'], 2), 2, 'items.'.$index.'.taxes');
            foreach ($totals as $field => $value) {
                $totals[$field] = bcadd($value, $line[$field], 2);
            }
        }
        unset($line);
        $declared = Decimal::input((string) ($header['declared_total'] ?? ''),2,'invoice.declared_total');
        if (bccomp($declared,$totals['payable'],2) !== 0) {
            throw ValidationException::withMessages(['invoice.declared_total' => 'O valor a pagar calculado (R$ '.$totals['payable'].') difere do total informado da nota.']);
        }

        return ['gross' => $gross, 'charges' => $charges, 'declared_total' => $declared, 'totals' => $totals, 'lines' => $lines];
    }
}
