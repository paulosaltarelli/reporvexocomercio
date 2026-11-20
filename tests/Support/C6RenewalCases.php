<?php

namespace Tests\Support;

use App\Platform\Billing\C6Gateway;
use App\Platform\Billing\CompanyPix;
use App\Platform\ContractBilling;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait C6RenewalCases
{
    public function test_c6_renews_only_after_confirming_expiry_and_keeps_previous_receipts_reconcilable(): void
    {
        config(['c6pix.key' => 'plataforma@example.test']);
        $actor = $this->licensingAdmin();
        $contract = app(ContractBilling::class)->create($this->a->id, $this->licensingTerms(), $actor->id, $actor->auth_version);
        $installment = $contract->installments()->sole();
        $bank = new class implements C6Gateway {
            public array $records = [];
            public array $credits = [];
            public bool $loseFirstResponse = true;

            public function account(): string { return 'fake-renewal-account'; }

            public function charge(string $txid, string $amount, bool $create): array
            {
                if (!isset($this->records[$txid])) {
                    if (!$create) {
                        throw new \LogicException('Cobrança inexistente no banco de teste');
                    }
                    $this->records[$txid] = ['txid' => $txid, 'valor' => ['original' => $amount], 'status' => 'ATIVA',
                        'calendario' => ['criacao'=>now()->toIso8601String(), 'expiracao'=>3600],
                        'pixCopiaECola' => 'pix-teste-'.$txid, 'chave' => config('c6pix.key')];
                    if ($this->loseFirstResponse) {
                        $this->loseFirstResponse = false;
                        throw ValidationException::withMessages(['pix' => 'Timeout de teste após criação.']);
                    }
                }
                return $this->records[$txid];
            }

            public function receipt(string $endToEndId): array { return $this->credits[$endToEndId]; }
        };
        $this->app->instance(C6Gateway::class, $bank);
        $pix = app(CompanyPix::class);
        $tenantId = $this->a->id;
        try {
            $pix->sync($tenantId, $installment->id, true);
            $this->fail('O teste requer resposta incerta na criação.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('pix', $e->errors());
        }
        $original = $pix->sync($tenantId, $installment->id, true);
        $this->assertCount(1, $bank->records);
        $this->assertSame('pending', $original['status']);
        $this->assertStringStartsWith("\x89PNG", base64_decode($original['qr_png'], true));
        $txid = $original['txid'];
        $bank->records[$txid]['calendario']['criacao'] = now()->subHours(2)->toIso8601String();
        $data = $pix->ensureForWorker($tenantId, $installment->id);
        $this->assertSame('expired', $data['status']);
        $this->assertCount(1, $bank->records);
        config(['c6pix.issuance_paused' => true]);
        $this->assertFalse($pix->availableFor($this->a));
        try {
            $pix->sync($tenantId, $installment->id, true);
            $this->fail('A emissão deve ficar suspensa durante a reversão.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('pix', $e->errors());
        }
        $this->assertCount(1, $bank->records);
        config(['c6pix.issuance_paused' => false]);
        $new = $pix->sync($tenantId, $installment->id, true);
        $this->assertNotSame($txid, $new['txid']);
        $this->assertCount(2, $bank->records);
        $e2e = 'E'.str_repeat('2', 31);
        $bank->credits[$e2e] = ['endToEndId'=>$e2e, 'txid'=>$txid, 'valor'=>'120.00', 'horario'=>now()->subHours(2)->toIso8601String()];
        $bank->records[$txid]['status'] = 'CONCLUIDA';
        $bank->records[$txid]['pix'] = [$bank->credits[$e2e]];
        $oldId = DB::connection('central')->table('platform_c6_pix_charges')->where('txid', $txid)->value('id');
        config(['c6pix.issuance_paused' => true]);
        $paid = $pix->reconcile($oldId);
        $this->assertSame('paid', $paid['status']);
        $this->assertNull($paid['code']);
        $pix->reconcile($oldId);
        $this->assertSame('0.00', $installment->fresh()->balance);
        $this->assertSame(1, $installment->payments()->count());
    }
}
