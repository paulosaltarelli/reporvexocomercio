<?php

namespace Tests\Support;

use App\Platform\Billing\C6Gateway;
use App\Platform\Contract;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

trait LicensingWorkerCases
{
    public function test_licensing_worker_is_exclusive_and_generates_without_bank_credentials(): void
    {
        Http::preventStrayRequests();
        config(['licensing_worker.reconcile'=>false,'licensing_worker.issue'=>false]);
        $day = today('America/Sao_Paulo');
        $contract = Contract::create(['tenant_id'=>$this->company->id,'start_date'=>$day,'end_date'=>$day,'due_day'=>$day->day,'monthly_amount'=>'39.00']);
        config(['database.connections.worker_contender'=>config('database.connections.central')]);
        $other = DB::connection('worker_contender');
        $key = 'vendra-licensing-'.substr(hash('sha256', $other->getDatabaseName()), 0, 40);
        try {
            $this->assertEquals(1,$other->selectOne('SELECT GET_LOCK(?,0) AS acquired', [$key])->acquired);
            $this->assertSame(0,Artisan::call('platform:licensing-work'));
            $this->assertSame(0,$contract->installments()->count());
        } finally {
            $other->selectOne('SELECT RELEASE_LOCK(?)',[$key]);
            DB::purge('worker_contender');
        }
        $this->assertSame(0,Artisan::call('platform:licensing-work'));
        $this->assertSame(0,Artisan::call('platform:licensing-work'));
        $this->assertSame(1,$contract->installments()->count());
        $this->assertSame(0,DB::connection('central')->table('platform_c6_pix_charges')->count());
        config(['licensing_worker.issue'=>true]);
        $this->assertSame(1,Artisan::call('platform:licensing-work'));
        $this->assertSame(2,Artisan::call('platform:licensing-work',['--limit'=>'0']));
        Http::assertNothingSent();
    }

    public function test_licensing_worker_accepts_independent_sandbox_for_reconciliation_only(): void
    {
        Http::preventStrayRequests();
        config([
            'licensing_worker.reconcile' => true,
            'licensing_worker.issue' => false,
            'c6pix.enabled' => false,
            'c6pix.environment' => 'production',
            'c6pix.sandbox.enabled' => true,
            'c6pix.sandbox.approval_reference' => 'sandbox-fixture',
        ]);
        $this->assertSame(0, Artisan::call('platform:licensing-work'));

        config(['licensing_worker.issue' => true]);
        $this->assertSame(1, Artisan::call('platform:licensing-work'));
        Http::assertNothingSent();
    }

    public function test_central_operator_saves_independent_bank_environments_and_audits_the_change(): void
    {
        Http::preventStrayRequests();
        $this->centralSession();
        $url = route('platform.settings.banks.save', ['c6', 'sandbox']);
        $this->get(route('platform.settings.banks.index'))->assertOk()->assertSee('C6 Bank');
        $this->post($url, ['enabled' => 1, 'issue' => 1, 'reconcile' => 1, 'auto_issue' => 1])
            ->assertSessionHasErrors('auto_issue');
        $this->post($url, ['enabled' => 1, 'issue' => 0, 'reconcile' => 1, 'auto_issue' => 0])
            ->assertRedirect(route('platform.settings.banks.index'));
        $banks = app(\App\Platform\Billing\BankEnvironments::class);
        $this->assertFalse($banks->permits('c6', 'sandbox', 'issue'));
        $this->assertTrue($banks->permits('c6', 'sandbox', 'reconcile'));
        $this->assertNull($banks->settings('c6', 'production'));

        config(['c6pix.credential_sets.sandbox.qa' => ['client_secret' => 'never-render-this']]);
        $flags = ['enabled' => 1, 'issue' => 0, 'reconcile' => 1, 'auto_issue' => 0];
        $this->post($url, $flags + ['token_url' => 'https://baas-api.c6bank.info/v1/auth'])
            ->assertSessionHasErrors('token_url');
        $this->post($url, $flags + ['credential_set' => 'missing'])
            ->assertSessionHasErrors('credential_set');
        $tokenUrl = 'https://baas-api-sandbox.c6bank.info/v1/auth';
        $this->post($url, $flags + ['token_url' => $tokenUrl, 'credential_set' => 'qa'])
            ->assertRedirect(route('platform.settings.banks.index'));
        $this->assertEquals(['token_url' => $tokenUrl, 'credential_set' => 'qa'],
            $banks->connection($banks->settings('c6', 'sandbox')));
        $this->get(route('platform.settings.banks.index'))->assertOk()->assertSee($tokenUrl)
            ->assertDontSee('never-render-this');
        $this->assertSame(2, \App\Platform\PlatformAudit::where('action', 'platform.bank_environment_updated')->count());
        Http::assertNothingSent();
    }

    public function test_licensing_worker_limits_first_issue_resumes_same_txid_and_does_not_renew_expired_charge(): void
    {
        Http::preventStrayRequests();
        config(['licensing_worker.reconcile'=>true, 'licensing_worker.issue'=>true,'c6pix.enabled'=>true,
            'c6pix.environment'=>'production','c6pix.approval_reference'=>'test-fixture','c6pix.key'=>'test-fixture-key']);
        $day = today('America/Sao_Paulo');
        $future = $day->copy()->addDays(8);
        foreach ([$day, $future] as $due) {
            Contract::create(['tenant_id'=>$this->company->id,'start_date'=>$due,'end_date'=>$due,'due_day'=>$due->day,'monthly_amount'=>'39.00']);
        }
        $bank = new class implements C6Gateway {
            public array $ids = [];
            public bool $expired = false;
            public bool $unavailable = true;
            public function account(): string { return hash('sha256', 'worker-fixture'); }
            public function charge(string $txid, string $amount, bool $create): array
            {
                if ($create) $this->ids[] = $txid;
                if ($this->unavailable) throw new \RuntimeException('synthetic failure');
                return ['txid'=>$txid,'status'=>'ATIVA','valor'=>['original'=>$amount],'chave'=>'test-fixture-key',
                    'calendario'=>['criacao'=>($this->expired ? now('UTC')->subDays(1) : now('UTC'))->toIso8601ZuluString(),'expiracao'=>3600],
                    'pixCopiaECola'=>'SYNTHETIC'];
            }
            public function receipt(string $id): array { throw new \LogicException('No receipt expected'); }
        };
        $this->app->instance(C6Gateway::class,$bank);
        $this->assertSame(1,Artisan::call('platform:licensing-work',['--limit'=>1]));
        $this->assertCount(1,$bank->ids);
        $db=DB::connection('central');
        $row=$db->table('platform_c6_pix_charges')->sole();
        $this->assertSame('reserved',$row->status);
        $this->assertSame($bank->ids[0],$row->txid);
        $bank->unavailable=false;
        $db->table('platform_c6_pix_charges')->where('id',$row->id)->update(['worker_next_check_at'=>now()->addHour()]);
        $db->table('platform_contract_installments')->where('id',$row->installment_id)->update(['pix_issue_retry_at'=>null]);
        $this->assertSame(0,Artisan::call('platform:licensing-work',['--limit'=>1]));
        $this->assertSame([$row->txid,$row->txid],$bank->ids);
        $this->assertSame('pending',$db->table('platform_c6_pix_charges')->sole()->status);
        $bank->expired=true;
        $db->table('platform_c6_pix_charges')->where('id',$row->id)->update(['worker_next_check_at'=>null]);
        $this->assertSame(0,Artisan::call('platform:licensing-work',['--limit'=>1]));
        $this->assertSame('expired',$db->table('platform_c6_pix_charges')->sole()->status);
        $this->assertCount(2,$bank->ids);
        Http::assertNothingSent();
    }
}
