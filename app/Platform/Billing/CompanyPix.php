<?php

namespace App\Platform\Billing;

use App\Fiscal\Snapshot;
use App\Models\Tenant;
use App\Platform\ContractBilling;
use App\Platform\ContractInstallment;
use Carbon\CarbonImmutable;
use Com\Tecnick\Barcode\Barcode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CompanyPix
{
    public function __construct(private C6Gateway $gateway, private ContractBilling $billing, private BankEnvironments $environments) {}

    public function availableFor(Tenant $tenant): bool
    {
        $environment = $tenant->is_demonstration ? 'sandbox' : 'production';
        return !config('c6pix.issuance_paused')
            && $this->environments->permits('c6', $environment, 'issue')
            && (!$this->gateway instanceof C6Bank || C6Bank::enabledForTenant($tenant));
    }

    private function bank(int $tenantId): C6Gateway
    {
        $tenant = Tenant::findOrFail($tenantId);
        $environment = $tenant->is_demonstration ? 'sandbox' : 'production';
        if (!$this->environments->permits('c6', $environment, 'enabled')) {
            throw C6Bank::error('O ambiente bancário da empresa está desabilitado na Central.');
        }

        return $this->gateway instanceof C6Bank
            ? $this->gateway->forTenant($tenant)
            : $this->gateway;
    }

    private function charges(): Builder
    {
        return DB::connection('central')->table('platform_c6_pix_charges');
    }

    private function attempts(int $tenantId, int $installmentId): Builder
    {
        return $this->charges()->where('tenant_id', $tenantId)->where('installment_id', $installmentId);
    }

    public function view(int $tenantId, int $installmentId): ?array
    {
        $invoice = ContractInstallment::where('tenant_id', $tenantId)->findOrFail($installmentId);
        $query = $this->attempts($tenantId, $installmentId);
        $row = (clone $query)->where('status', 'paid')->latest('id')->first() ?? $query->latest('id')->first();
        if (!$row) {
            return null;
        }
        $expires = $row->expires_at ? CarbonImmutable::parse($row->expires_at, 'UTC') : null;
        $status = $row->status;
        if ($status === 'pending' && $expires?->isPast()) {
            $status = 'expired';
        }
        $code = null;
        $png = null;
        if ($status === 'pending' && !$invoice->cancelled_at && $invoice->balance === $row->amount
            && $row->code && $expires?->isFuture()) {
            try {
                $canDisplay = hash_equals($row->account, $this->bank($tenantId)->account());
            } catch (ValidationException) {
                $canDisplay = false;
            }
            if ($canDisplay) {
                $code = $row->code;
            }
        }
        if ($code !== null) {
            $image = (new Barcode)->getBarcodeObj('QRCODE,M', $code, -4, -4, 'black', [16,16,16,16]);
            $image->setBackgroundColor('white');
            $png = base64_encode($image->getPngData(false));
        }
        return ['status' => $status === 'reserved' ? 'pending' : $status, 'code' => $code, 'qr_png' => $png,
            'expires_at' => $expires?->toIso8601ZuluString(), 'txid' => $row->txid, 'amount' => $row->amount,
            'end_to_end_id' => $row->end_to_end_id, 'environment' => $row->environment];
    }

    public function sync(int $tenantId, int $installmentId, bool $create): array
    {
        return $this->process($tenantId, $installmentId, $create, $create);
    }

    public function ensureForWorker(int $tenantId, int $installmentId): array
    {
        return $this->process($tenantId, $installmentId, true, false);
    }

    public function reconcile(int $chargeId): array
    {
        $row = $this->charges()->where('id', $chargeId)->firstOrFail();
        if (!$this->environments->permits('c6', $row->environment, 'reconcile')) {
            throw C6Bank::error('A conciliação deste ambiente está desabilitada na Central.');
        }
        $bank = $this->bank($row->tenant_id);
        $this->refresh($row, $bank, $bank->account(), false);
        return $this->view($row->tenant_id, $row->installment_id);
    }

    private function process(int $tenantId, int $installmentId, bool $create, bool $renew): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        $environment = $tenant->is_demonstration ? 'sandbox' : 'production';
        if ($create && (config('c6pix.issuance_paused') || !$this->environments->permits('c6', $environment, 'issue'))) {
            throw C6Bank::error('A emissão de novas cobranças Pix está temporariamente indisponível.');
        }
        $bank = $this->bank($tenantId);
        $account = $bank->account();
        $worker = $create && !$renew;
        $row = $worker
            ? $this->reserve($tenantId, $installmentId, $account, worker: true)
            : $this->attempts($tenantId, $installmentId)->latest('id')->first();
        if (!$row) {
            if (!$create) {
                throw C6Bank::error('A parcela ainda não possui uma cobrança Pix.');
            }
            $row = $this->reserve($tenantId, $installmentId, $account);
        }
        $status = $this->refresh($row, $bank, $account, $create && $row->status === 'reserved');
        if ($renew && $status === 'expired') {
            $next = $this->reserve($tenantId, $installmentId, $account, $row->id);
            $this->refresh($next, $bank, $account, $next->status === 'reserved');
        }
        return $this->view($tenantId, $installmentId);
    }

    private function reserve(int $tenantId, int $installmentId, string $account, ?int $expiredId = null, bool $worker = false): object
    {
        return DB::connection('central')->transaction(function () use ($tenantId, $installmentId, $account, $expiredId, $worker) {
            $tenant = Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            if (!$tenant->active || ($worker && $tenant->is_demonstration)) {
                throw C6Bank::error('A empresa precisa estar ativa. Na demonstração, gere a cobrança manualmente no sandbox.');
            }
            if ($this->gateway instanceof C6Bank && !hash_equals($account, $this->gateway->forTenant($tenant)->account())) {
                throw C6Bank::error('O ambiente bancário da empresa mudou. Atualize a tela.');
            }
            $invoice = $this->billing->lockForCharge($tenantId, $installmentId);
            if ($worker && $invoice->due_date->toDateString() > today()->addDays(7)->toDateString()) {
                throw C6Bank::error('A geração automática começa sete dias antes do vencimento.');
            }
            $previous = $this->attempts($tenantId, $installmentId)->latest('id')->lockForUpdate()->first();
            if ($previous && ((int) $previous->id !== $expiredId || $previous->status !== 'expired')) {
                return $previous;
            }
            if (!preg_match('/^\d{1,10}\.\d{2}$/D', $invoice->balance) || bccomp($invoice->balance, '0', 2) <= 0) {
                throw C6Bank::error('A parcela precisa ter saldo positivo compatível com a API Pix.');
            }
            $id = $this->charges()->insertGetId(['tenant_id' => $tenantId, 'installment_id' => $installmentId,
                'txid' => str_replace('-', '', (string) Str::uuid()), 'amount' => $invoice->balance, 'account' => $account,
                'environment' => $tenant->is_demonstration ? 'sandbox' : 'production',
                'status' => 'reserved', 'created_at' => now(), 'updated_at' => now()]);
            return $this->charges()->find($id);
        }, 3);
    }

    private function refresh(object $row, C6Gateway $gateway, string $account, bool $create): string
    {
        if (!hash_equals($row->account, $account)) {
            throw C6Bank::error('Esta cobrança pertence a outra configuração bancária da plataforma.');
        }
        if ($row->status === 'paid') {
            return 'paid';
        }
        $invoice = ContractInstallment::where('tenant_id', $row->tenant_id)->findOrFail($row->installment_id);
        $bank = $gateway->charge($row->txid, $row->amount, $create && !$invoice->cancelled_at && !$invoice->contract->inImplantationGrace() && $invoice->balance === $row->amount);
        $receipt = null;
        $key = $gateway instanceof C6Bank ? $gateway->receivingKey() : (string) config('c6pix.key');
        $state = $this->inspect($row, $bank, $key);
        if ($state['status'] === 'paid') {
            $receipt = $gateway->receipt($state['end_to_end_id']);
            if (!$this->matchesReceipt($row, $receipt) || $receipt['endToEndId'] !== $state['end_to_end_id']
                || !$this->instant($receipt['horario'])->equalTo($this->instant($bank['pix'][0]['horario']))) {
                $state = ['status' => 'review'];
            } else {
                $state['receipt_hash'] = Snapshot::hash(array_intersect_key($receipt, array_flip(['endToEndId','txid','horario','valor'])));
            }
        }
        $status = DB::connection('central')->transaction(function () use ($row, $state) {
            $invoice = ContractInstallment::where('tenant_id', $row->tenant_id)->lockForUpdate()->findOrFail($row->installment_id);
            $current = $this->charges()->where('id', $row->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'paid') {
                return 'paid';
            }
            if ($state['status'] === 'paid') {
                try {
                    $this->billing->confirmPix($row->tenant_id, $row->installment_id, $row->txid, $state['end_to_end_id'], $row->amount);
                } catch (ValidationException) {
                    // Preserve the separately verified credit for operator review, without a second settlement.
                    $state['status'] = 'review';
                }
            } elseif ($invoice->cancelled_at || $invoice->balance !== $row->amount) {
                $state = ['status' => 'review'];
            } elseif (($state['status'] === 'pending' && $current->status === 'expired') || $current->status === 'review') {
                // An older in-flight read must not re-expose a retired or disputed code.
                return $current->status;
            }
            $this->charges()->where('id', $row->id)->update($state + ['code' => null, 'updated_at' => now()]);
            return $state['status'];
        }, 3);
        if ($status === 'review') {
            throw C6Bank::error('Dados bancários ou saldo divergentes. Confira o txid e o recebimento no C6 antes de prosseguir.');
        }
        return $status;
    }

    private function inspect(object $row, array $bank, string $key): array
    {
        if (($bank['txid'] ?? null) !== $row->txid || ($bank['valor']['original'] ?? null) !== $row->amount
            || !is_string($bank['chave'] ?? null) || !hash_equals($key, $bank['chave'])) {
            return ['status' => 'review'];
        }
        if (($bank['status'] ?? '') === 'ATIVA' && empty($bank['pix'])) {
            $created = $this->instant($bank['calendario']['criacao'] ?? null);
            $lifetime = $bank['calendario']['expiracao'] ?? null;
            if (!$created || !is_int($lifetime) || $lifetime < 1 || $lifetime > 2147483647) {
                return ['status' => 'review'];
            }
            $expires = $created->addSeconds($lifetime);
            if ($expires->isPast()) {
                return ['status' => 'expired', 'expires_at' => $expires->toDateTimeString()];
            }
            $code = $bank['pixCopiaECola'] ?? '';
            if (!is_string($code) || $code === '' || strlen($code) > 512) {
                return ['status' => 'review'];
            }
            return ['status' => 'pending', 'code' => $code, 'expires_at' => $expires->toDateTimeString()];
        }
        $list = $bank['pix'] ?? null;
        if (($bank['status'] ?? '') !== 'CONCLUIDA' || !is_array($list) || count($list) !== 1
            || !is_array($list[0] ?? null) || !$this->matchesReceipt($row, $list[0])) {
            return ['status' => 'review'];
        }
        return ['status' => 'paid', 'end_to_end_id' => $list[0]['endToEndId'],
            'bank_paid_at' => $this->instant($list[0]['horario'])->format('Y-m-d H:i:s.u')];
    }

    private function matchesReceipt(object $row, array $receipt): bool
    {
        $paid = $this->instant($receipt['horario'] ?? null);
        return ($receipt['txid'] ?? '') === $row->txid && ($receipt['valor'] ?? '') === $row->amount
            && is_string($receipt['endToEndId'] ?? null) && preg_match('/^E[A-Za-z0-9]{31}$/D', $receipt['endToEndId'])
            && empty($receipt['devolucoes']) && $paid && !$paid->isFuture();
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)$/D', $value)) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($value);
            return \DateTimeImmutable::getLastErrors() ? null : CarbonImmutable::instance($date)->utc();
        } catch (\Exception) {
            return null;
        }
    }
}
