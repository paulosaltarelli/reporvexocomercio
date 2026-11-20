<?php

namespace App\Console\Commands;

use App\Platform\Billing\CompanyPix;
use App\Platform\Contract;
use App\Platform\ContractBilling;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

final class LicensingWork extends Command
{
    protected $signature = 'platform:licensing-work {--limit=20 : Limite por operação bancária (1 a 100)}';
    protected $description = 'Gera competências e processa a fila Pix das licenças da plataforma.';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>100]]);
        if ($limit === false) {
            $this->error('Informe um limite de 1 a 100.');
            return self::INVALID;
        }
        $db = DB::connection('central');
        $lock = 'vendra-licensing-'.substr(hash('sha256', $db->getDatabaseName()), 0, 40);
        $acquired = $db->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lock], false)->acquired;
        if ((string)$acquired === '0') {
            $this->info('Outra execução está em andamento.');
            return self::SUCCESS;
        }
        if ((string)$acquired !== '1') {
            $this->error('Não foi possível adquirir o bloqueio do ciclo.');
            return self::FAILURE;
        }
        try {
            return $this->process($db, $limit);
        } finally {
            $db->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock], false);
        }
    }

    private function process(Connection $db, int $limit): int
    {
        $failed = false;
        $generated = 0;
        $billing = app(ContractBilling::class);
        foreach (Contract::query()->select('id','tenant_id')->lazyById(250) as $contract) {
            try {
                $generated += $billing->generate($contract->tenant_id, $contract->id);
            } catch (\Throwable) {
                $this->error("Geração pendente no contrato {$contract->id}.");
                $failed = true;
            }
        }
        $this->info("Competências criadas: {$generated}.");
        $reconcile = config('licensing_worker.reconcile');
        $issue = config('licensing_worker.issue') && !config('c6pix.issuance_paused');
        if ($reconcile || $issue) {
            $primary = config('c6pix');
            $production = ($primary['environment'] ?? null) === 'production' && $this->approved($primary);
            $sandboxProfile = ($primary['environment'] ?? null) === 'sandbox' ? $primary : ($primary['sandbox'] ?? []);
            $sandbox = $this->approved($sandboxProfile);
            if (!$reconcile || (!$production && !$sandbox) || ($issue && !$production)) {
                $this->error('A automação bancária requer conciliação habilitada e integração C6 homologada.');
                return self::FAILURE;
            }
            $pix = app(CompanyPix::class);
            $environments = array_keys(array_filter(['production' => $production, 'sandbox' => $sandbox]));
            if (!$this->reconcile($db, $pix, $limit, $environments)) $failed = true;
            if ($issue && !$this->issue($db, $pix, $limit)) $failed = true;
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function approved(array $profile): bool
    {
        return !empty($profile['enabled']) && filled($profile['approval_reference'] ?? null);
    }

    private function reconcile(Connection $db, CompanyPix $pix, int $limit, array $environments): bool
    {
        $now = now();
        $expiredSince = now('UTC')->subDays(7)->toDateTimeString();
        $charges = $db->table('platform_c6_pix_charges')
            ->whereIn('environment', $environments)
            ->where(function ($q) use ($expiredSince) {
                $q->whereIn('status', ['reserved','pending'])->orWhere(function ($expired) use ($expiredSince) {
                    $expired->where('status','expired')->where('expires_at','>=',$expiredSince);
                });
            })
            ->where(fn ($q) => $q->whereNull('worker_next_check_at')->orWhere('worker_next_check_at','<=',$now))
            ->orderByRaw('COALESCE(worker_next_check_at, created_at)')->orderBy('id')->limit($limit)->get(['id','status']);
        $ok = true;
        foreach ($charges as $charge) {
            $row = $db->table('platform_c6_pix_charges')->where('id',$charge->id);
            // Advance before I/O: process termination delays only this record, never the whole batch.
            $claimed = (clone $row)->where('status',$charge->status)
                ->where(fn ($q) => $q->whereNull('worker_next_check_at')->orWhere('worker_next_check_at','<=',$now))
                ->update(['worker_next_check_at'=>now()->addMinutes(15)]);
            if (!$claimed) continue;
            try {
                $pix->reconcile($charge->id);
                $row->update(['worker_next_check_at'=>$charge->status === 'expired' ? now()->addHours(6) : now()->addMinutes(5)]);
            } catch (\Throwable) {
                $this->error("Consulta bancária pendente na cobrança {$charge->id}.");
                $ok = false;
            }
        }
        return $ok;
    }

    private function issue(Connection $db, CompanyPix $pix, int $limit): bool
    {
        $now = now();
        $installments = $db->table('platform_contract_installments as i')->join('tenants as t','i.tenant_id','=','t.id')
            ->join('platform_contracts as c', 'i.contract_id', '=', 'c.id')
            ->where(fn ($q) => $q->where('c.implantation_grace_days', 0)
                ->orWhereRaw('DATEDIFF(?, c.start_date) >= c.implantation_grace_days', [today()->toDateString()]))
            ->where('t.active', true)->where('t.is_demonstration', false)->whereNull('i.cancelled_at')
            ->whereColumn('i.amount','>','i.paid_amount')
            ->where('i.due_date','<=',now('America/Sao_Paulo')->addDays(7)->toDateString())
            ->where(fn ($q) => $q->whereNull('i.pix_issue_retry_at')->orWhere('i.pix_issue_retry_at','<=',$now))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('platform_c6_pix_charges as c')
                ->whereColumn('c.tenant_id','i.tenant_id')->whereColumn('c.installment_id','i.id')->where('c.status','<>','reserved'))
            ->orderByRaw('COALESCE(i.pix_issue_retry_at, i.created_at)')->orderBy('i.due_date')->orderBy('i.id')
            ->limit($limit)->get(['i.id','i.tenant_id']);
        $ok = true;
        foreach ($installments as $installment) {
            $claimed = $db->table('platform_contract_installments')->where('id',$installment->id)->where('tenant_id',$installment->tenant_id)
                ->whereNull('cancelled_at')->whereColumn('amount','>','paid_amount')
                ->where(fn ($q) => $q->whereNull('pix_issue_retry_at')->orWhere('pix_issue_retry_at','<=',$now))
                ->update(['pix_issue_retry_at'=>now()->addMinutes(15)]);
            if (!$claimed) continue;
            try {
                $pix->ensureForWorker($installment->tenant_id, $installment->id);
            } catch (\Throwable) {
                $this->error("Emissão Pix pendente na parcela {$installment->id}.");
                $ok = false;
            }
        }
        return $ok;
    }
}
