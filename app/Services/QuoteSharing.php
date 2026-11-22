<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Carbon;

final class QuoteSharing
{
    public static function message(Document $quote, string $store): string
    {
        $lines = [trim($store).' · Orçamento #'.$quote->id, ''];
        foreach ($quote->items as $item) {
            $quantity = rtrim(rtrim(number_format($item['quantity'], 3, ',', '.'), '0'), ',');
            $description = preg_replace('/\s+/u', ' ', $item['description']);
            $lines[] = $quantity.' × '.trim($description).' — R$ '.number_format($item['total'], 2, ',', '.');
        }
        $lines[] = '';
        $lines[] = 'Total do orçamento: R$ '.number_format($quote->total, 2, ',', '.');
        if ($quote->valid_until) {
            $date = Carbon::parse($quote->valid_until);
            $lines[] = ($date->lt(today()) ? 'Validade encerrada em ' : 'Válido até ').$date->format('d/m/Y').'.';
        } else {
            $lines[] = 'Validade não informada.';
        }

        return implode("\n", $lines);
    }

    public static function phone(?string $registered): ?string
    {
        $number = preg_replace('/[\s().-]+/', '', trim((string) $registered));

        if (str_starts_with($number, '+') || str_starts_with($number, '00')) {
            $digits = substr($number, str_starts_with($number, '+') ? 1 : 2);

            return preg_match('/^[1-9][0-9]{7,14}$/D', $digits) ? $digits : null;
        }
        if (preg_match('/^[1-9][0-9]{9,10}$/D', $number)) {
            return '55'.$number;
        }

        return preg_match('/^55[1-9][0-9]{9,10}$/D', $number) ? $number : null;
    }
}
