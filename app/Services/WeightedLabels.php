<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** EAN-13 labels are decoded only after an administrator enables a matching format. */
final class WeightedLabels
{
    public const DEFAULTS = [
        'enabled' => false, 'prefix' => '20', 'plu_length' => 5,
        'value_length' => 5, 'content' => 'weight', 'decimals' => 3,
    ];

    public static function normalize(array $input): array
    {
        $settings = array_replace(self::DEFAULTS, $input);
        $validated = Validator::make(['weighed_labels' => $settings], [
            'weighed_labels.enabled' => 'required|boolean',
            'weighed_labels.prefix' => ['required', 'string', 'regex:/^[0-9]{2,4}$/D'],
            'weighed_labels.plu_length' => 'required|integer|min:1|max:9',
            'weighed_labels.value_length' => 'required|integer|min:1|max:9',
            'weighed_labels.content' => 'required|in:weight,price',
            'weighed_labels.decimals' => 'required|integer|min:0|max:3',
        ])->validate()['weighed_labels'];
        $validated['enabled'] = (bool) $validated['enabled'];
        foreach (['plu_length', 'value_length', 'decimals'] as $field) $validated[$field] = (int) $validated[$field];
        if (strlen($validated['prefix']) + $validated['plu_length'] + $validated['value_length'] !== 12) {
            throw ValidationException::withMessages(['weighed_labels.plu_length' => 'Prefixo, código PLU e valor devem somar 12 dígitos, antes do dígito verificador.']);
        }
        if ($validated['content'] === 'price' && $validated['decimals'] !== 2) {
            throw ValidationException::withMessages(['weighed_labels.decimals' => 'Para valor em reais, use duas casas decimais.']);
        }
        return $validated;
    }

    public function current(): array
    {
        return self::normalize(app(CompanyOperations::class)->current()['weighed_labels'] ?? []);
    }

    public static function checkDigit(string $digits): string
    {
        if (!preg_match('/^[0-9]{12}$/D', $digits)) {
            throw new \InvalidArgumentException('O cálculo EAN-13 exige 12 dígitos.');
        }
        $sum = 0;
        for ($index = 0; $index < 12; $index++) {
            $sum += (int) $digits[$index] * ($index % 2 === 0 ? 1 : 3);
        }
        return (string) ((10 - $sum % 10) % 10);
    }

    public function decode(string $code): ?array
    {
        $settings = $this->current();
        if (!$settings['enabled'] || !str_starts_with($code, $settings['prefix'])) return null;
        if (!preg_match('/^[0-9]{13}$/D', $code) || self::checkDigit(substr($code, 0, 12)) !== $code[12]) {
            throw ValidationException::withMessages(['code' => 'A etiqueta pesável está incompleta ou tem dígito verificador inválido. Leia novamente.']);
        }
        $start = strlen($settings['prefix']);
        $plu = substr($code, $start, $settings['plu_length']);
        $rawValue = substr($code, $start + $settings['plu_length'], $settings['value_length']);
        $divisor = '1'.str_repeat('0', $settings['decimals']);
        $value = bcdiv($rawValue, $divisor, $settings['decimals']);
        if (bccomp($value, '0', $settings['decimals']) <= 0) {
            throw ValidationException::withMessages(['code' => 'A etiqueta pesável precisa informar peso ou valor maior que zero.']);
        }
        return ['code' => $code, 'plu' => $plu, 'content' => $settings['content'], 'value' => $value];
    }

    public function quantity(array $label, string $catalogPrice): string
    {
        if ($label['content'] === 'weight') {
            return Decimal::input($label['value'], 3, 'code', '0.001', '999999');
        }
        if (bccomp($catalogPrice, '0', 2) <= 0) {
            throw ValidationException::withMessages(['code' => 'O produto precisa ter preço por quilograma maior que zero para ler etiquetas de valor.']);
        }
        $quantity = Decimal::round(bcdiv($label['value'], $catalogPrice, 12), 3);
        $quantity = Decimal::input($quantity, 3, 'code', '0.001', '999999');
        if (bccomp(Decimal::round(bcmul($quantity, $catalogPrice, 12)), $label['value'], 2) !== 0) {
            throw ValidationException::withMessages(['code' => 'O valor da etiqueta não corresponde ao preço atual com peso em três casas decimais. Reetiquete o produto por peso.']);
        }
        return $quantity;
    }
}
