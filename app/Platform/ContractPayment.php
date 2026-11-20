<?php

namespace App\Platform;

use Illuminate\Database\Eloquent\Model;

final class ContractPayment extends Model
{
    protected $connection = 'central';

    protected $table = 'platform_contract_payments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('Recebimentos confirmados são imutáveis.'));
        self::deleting(fn () => throw new \LogicException('Preserve o histórico de recebimentos.'));
    }
}
