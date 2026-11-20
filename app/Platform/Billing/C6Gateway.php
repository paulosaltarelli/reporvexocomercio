<?php

namespace App\Platform\Billing;

interface C6Gateway
{
    public function account(): string;

    public function charge(string $txid, string $amount, bool $create): array;

    public function receipt(string $endToEndId): array;
}
