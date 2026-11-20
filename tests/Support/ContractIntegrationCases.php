<?php

namespace Tests\Support;

use App\Platform\Billing\C6Gateway;
use App\Platform\Contract;
use App\Platform\ContractBilling;
use App\Platform\ContractInstallment;
use App\Platform\PlatformAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

trait ContractIntegrationCases
{
    private function licensingAdmin(): PlatformAdmin
    {
        return PlatformAdmin::create([
            'name' => 'Gestão de licenças QA', 'email' => 'licencas@example.test',
            'password' => 'local-testing-only', 'active' => true, 'auth_version' => 0,
        ]);
    }

    private function licensingTerms(): array
    {
        return ['start_date' => '2026-11-01', 'end_date' => '2026-11-30',
            'due_day' => 5, 'monthly_amount' => '120.00', 'restricted_modules' => ['sales']];
    }

    public function test_contract_payment_through_store_routes_uses_verified_receipt_and_reopens_only_its_store(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-16 12:00:00'));
        Http::preventStrayRequests();
        config(['c6pix.key' => 'licenca@example.test']);
        $admin = $this->licensingAdmin();
        $this->withSession(['platform_admin_id' => $admin->id, 'platform_mfa_at' => time(), 'platform_auth_version' => 0]);
        foreach ([$this->a, $this->b] as $tenant) {
            $this->post("/vexo-admin/companies/{$tenant->id}/license/contracts", $this->licensingTerms() + ['implantation_grace_days' => 2, 'overdue_grace_days' => 3])
                ->assertRedirect()->assertSessionHasNoErrors();
        }
        $own = ContractInstallment::where('tenant_id', $this->a->id)->sole();
        $foreign = ContractInstallment::where('tenant_id', $this->b->id)->sole();

        $bank = new class implements C6Gateway {
            public bool $confirmed = false;
            public string $receiptAmount = '120.00';
            public int $calls = 0;
            public int $receiptReads = 0;
            private string $txid = '';

            public function account(): string
            {
                return 'qa-account';
            }

            public function charge(string $txid, string $amount, bool $create): array
            {
                ++$this->calls;
                $this->txid = $txid;
                return ['txid' => $txid, 'status' => $this->confirmed ? 'CONCLUIDA' : 'ATIVA',
                    'chave' => config('c6pix.key'), 'valor' => ['original' => '120.00'],
                    'pixCopiaECola' => 'copia-e-cola-de-teste',
                    'calendario' => ['criacao' => now()->toIso8601String(), 'expiracao' => 3600],
                    'pix' => $this->confirmed ? [$this->transfer('120.00')] : []];
            }

            public function receipt(string $endToEndId): array
            {
                ++$this->receiptReads;
                return $this->transfer($this->receiptAmount);
            }

            private function transfer(string $amount): array
            {
                return ['txid' => $this->txid, 'endToEndId' => 'E'.str_repeat('1', 31),
                    'valor' => $amount, 'horario' => now()->toIso8601String()];
            }
        };
        $this->app->instance(C6Gateway::class, $bank);
        $this->flushSession();
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $base = '/app/a/licenca/parcelas/';
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-02 12:00:00'));
        $this->get($base.$own->id)->assertOk()->assertSee('O Pix poderá ser gerado a partir de 03/11/2026')
            ->assertSee('A partir de 09/11/2026')->assertDontSee('Gerar Pix para pagamento');
        $this->post($base.$own->id.'/pix')->assertRedirect()->assertSessionHasErrors('installment');
        $this->assertSame(0, $bank->calls);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-16 12:00:00'));
        $this->get('/app/a/desk')->assertStatus(402);
        $this->get('/app/a/licenca')->assertOk();
        $this->get('/app/a/finance')->assertOk();
        $this->get($base.$own->id)->assertOk();
        $this->get($base.$foreign->id)->assertNotFound();
        $this->post($base.$foreign->id.'/pix')->assertNotFound();
        $this->post($base.$foreign->id.'/consultar')->assertNotFound();
        $this->assertSame(0, $bank->calls);
        $this->post($base.$own->id.'/pix')->assertRedirect()->assertSessionHasNoErrors();
        $this->get($base.$own->id)->assertOk()->assertSee('copia-e-cola-de-teste')->assertSee('data:image/png;base64,', false);
        $this->post($base.$own->id.'/consultar', ['paid' => 1, 'paid_amount' => '120.00', 'status' => 'CONCLUIDA'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('0.00', $own->fresh()->paid_amount);
        $this->get('/app/a/desk')->assertStatus(402);

        $bank->confirmed = true;
        $bank->receiptAmount = '119.99';
        $this->post($base.$own->id.'/consultar')->assertRedirect()->assertSessionHasErrors('pix');
        $this->get($base.$own->id)->assertOk()->assertSee('A cobrança está em conferência.')->assertDontSee('id="license-pix-code"', false);
        $this->assertSame('0.00', $own->fresh()->paid_amount);
        $bank->receiptAmount = '120.00';
        for ($i = 0; $i < 2; ++$i) {
            $this->post($base.$own->id.'/consultar')->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame('120.00', $own->fresh()->paid_amount);
        }
        $this->assertGreaterThanOrEqual(2, $bank->receiptReads);
        $this->assertSame(1, $own->payments()->count());
        $this->assertSame('0.00', $foreign->fresh()->paid_amount);
        $this->get('/app/a/desk')->assertOk();

        $foreign->contract->update(['restricted_modules' => ['sales', 'finance']]);
        app(\App\Services\TenantConnection::class)->connect($this->b);
        $secondOwner = \App\Models\User::create(['name' => 'Admin B', 'email' => 'adminb@example.test',
            'role' => 'admin', 'password' => 'local-testing-only']);
        $this->flushSession();
        $this->withSession(['tenant_id' => $this->b->id, 'user_id' => $secondOwner->id, 'auth_version' => 0]);
        $this->get('/app/b/desk')->assertStatus(402);
        $this->get('/app/b/finance')->assertStatus(402);
        $this->get('/app/b/licenca')->assertOk();
        $this->get('/app/b/licenca/parcelas/'.$foreign->id)->assertOk();
        $this->get('/app/b/licenca/parcelas/'.$own->id)->assertNotFound();
    }

    public function test_concurrent_contract_generation_and_pix_confirmation_create_one_period_and_credit_once(): void
    {
        // Imported terms awaiting their first generation, visible to both MySQL connections.
        $contract = Contract::create(['tenant_id' => $this->a->id] + $this->licensingTerms());
        $ids = $this->race(null, function () use ($contract) {
            app(ContractBilling::class)->generate($this->a->id, $contract->id);
            return $contract->installments()->sole()->id;
        });
        $this->assertSame($ids[0], $ids[1]);
        $installment = $contract->installments()->sole();
        $tenantId = $this->a->id;
        DB::connection('central')->transaction(fn () =>
            app(ContractBilling::class)->lockForCharge($tenantId, $installment->id));
        $ids = $this->race(null, function () use ($tenantId, $installment) {
            app(ContractBilling::class)->confirmPix($tenantId, $installment->id, str_repeat('G', 32),
                'E'.str_repeat('2', 31), '120.00');
            return $installment->id;
        });
        $this->assertSame($ids[0], $ids[1]);
        $this->assertSame('120.00', $installment->fresh()->paid_amount);
        $this->assertSame('0.00', $installment->fresh()->balance);
        $this->assertSame(1, $installment->payments()->count());
    }

    public function test_central_receivables_show_filtered_balance_not_face_value_and_totals_span_all_pages(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-16 12:00:00'));
        $admin = $this->licensingAdmin();
        $terms = $this->licensingTerms();
        $terms['start_date'] = '2024-05-01';
        $contract = app(ContractBilling::class)->create($this->a->id, $terms, $admin->id, 0);
        $contract->installments()->where('period', '2026-11-01')->update(['paid_amount' => '20.00']);
        app(ContractBilling::class)->create($this->b->id, $this->licensingTerms(), $admin->id, 0);
        $this->withSession(['platform_admin_id' => $admin->id, 'platform_mfa_at' => time(), 'platform_auth_version' => 0]);
        $filtered = '/vexo-admin/financeiro?status=overdue&q='.urlencode($this->a->name);
        $page = $this->get($filtered.'&page=2')->assertOk();
        $this->assertSame(31, $page->viewData('installments')->total());
        $this->assertCount(1, $page->viewData('installments'));
        $page->assertSee('Página 2 de 2')->assertSee('Anterior')->assertDontSee('Próxima');
        $this->get($filtered)->assertOk()->assertSee('Página 1 de 2')->assertSee('Próxima');
        $this->assertSame('3700.00', $page->viewData('totals')['overdue']);
        $month = $this->get($filtered.'&month=2026-11')->assertOk();
        $this->assertSame(1, $month->viewData('installments')->total());
        $this->assertSame('100.00', $month->viewData('totals')['overdue']);
        $this->assertSame('20.00', $month->viewData('totals')['paid']);
        $this->assertSame('overdue', $month->viewData('installments')->first()->payment_status);
        $dom = new \DOMDocument();
        @$dom->loadHTML($month->getContent());
        $cells = (new \DOMXPath($dom))->query('//table/tbody/tr[1]/td');
        $this->assertSame('R$ 120,00', trim($cells->item(3)->textContent));
        $this->assertSame('R$ 20,00', trim($cells->item(4)->textContent));
        $this->assertSame('R$ 100,00', trim($cells->item(5)->textContent));
    }
}
