<?php

namespace App\Platform\Billing;

use Illuminate\Support\Facades\DB;

class BankEnvironments
{
    public const BANKS = ['c6' => 'C6 Bank'];
    public const ENVIRONMENTS = ['sandbox' => 'Homologação', 'production' => 'Produção'];

    public function table()
    {
        return DB::connection('central')->table('platform_bank_environments');
    }

    public function settings(string $bank, string $environment): ?object
    {
        return $this->table()->where('bank', $bank)->where('environment', $environment)->first();
    }

    public function connection(?object $settings): array
    {
        return json_decode($settings?->connection_settings ?? '{}', true, flags: JSON_THROW_ON_ERROR);
    }

    public function permits(string $bank, string $environment, string $operation): bool
    {
        $settings = $this->settings($bank, $environment);

        return !$settings || ((bool) $settings->enabled && (bool) $settings->{$operation});
    }
}
