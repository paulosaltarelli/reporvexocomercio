<?php

namespace Tests\Unit;

use App\Platform\Billing\C6Bank;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class C6BankTest extends TestCase
{
    private function configure(): void
    {
        config(['c6pix' => [
            'enabled'=>true, 'environment'=>'sandbox', 'approval_reference'=>'teste-local',
            'api_url'=>'https://sandbox.example.c6bank.com.br/pix-api', 'token_url'=>'https://sandbox.example.c6bank.com.br/token',
            'auth_method'=>'client_secret_post', 'scope'=>null, 'client_id'=>'fake-id', 'client_secret'=>'fake-secret',
            'cert'=>__FILE__, 'private_key'=>__FILE__, 'passphrase'=>'', 'key'=>'recebedor@example.test',
        ]]);
        Http::preventStrayRequests();
    }

    public function test_c6_adapter_uses_authenticated_read_then_put_same_txid_and_reads_credit_by_e2e(): void
    {
        $this->configure();
        $txid = str_repeat('a', 32);
        $e2e = 'E'.str_repeat('2', 31);
        $base = config('c6pix.api_url');
        Http::fake([
            config('c6pix.token_url') => Http::response(['access_token'=>'fake-token']),
            $base.'/cob/'.$txid => Http::sequence()->push([],404)->push(['txid'=>$txid],201),
            $base.'/pix/'.$e2e => Http::response(['endToEndId'=>$e2e]),
        ]);
        $bank = new C6Bank();
        $this->assertSame($txid, $bank->charge($txid, '20.00', true)['txid']);
        $this->assertSame($e2e, $bank->receipt($e2e)['endToEndId']);
        Http::assertSent(fn (Request $r) => $r->url() === config('c6pix.token_url')
            && $r['grant_type'] === 'client_credentials' && $r['client_id'] === 'fake-id');
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === $base.'/cob/'.$txid
            && $r->hasHeader('Authorization','Bearer fake-token') && $r['valor']['original'] === '20.00'
            && $r['chave'] === 'recebedor@example.test');
        Http::assertSentCount(5);
    }

    public function test_c6_demo_uses_independent_sandbox_even_when_application_runs_in_production(): void
    {
        $this->configure();
        $sandbox = config('c6pix');
        $production = $sandbox;
        $production['environment'] = 'production';
        $production['api_url'] = 'https://production.example.c6bank.com.br/pix-api';
        $production['token_url'] = 'https://production.example.c6bank.com.br/token';
        $production['key'] = 'empresa-real@example.test';
        $production['sandbox'] = $sandbox;
        config(['c6pix' => $production]);
        $this->app->detectEnvironment(fn () => 'production');
        $demo = new \App\Models\Tenant;
        $demo->is_demonstration = true;
        $live = new \App\Models\Tenant;
        $live->is_demonstration = false;
        $this->assertSame($sandbox['key'], (new C6Bank())->forTenant($demo)->receivingKey());
        $this->assertSame($production['key'], (new C6Bank())->forTenant($live)->receivingKey());
        config(['c6pix.sandbox.token_url' => $production['token_url']]);
        $this->assertFalse(C6Bank::enabledForTenant($demo));
        $this->assertTrue(C6Bank::enabledForTenant($live));
        config(['c6pix' => $sandbox]);
        $this->assertFalse(C6Bank::enabledForTenant($live));
        Http::assertNothingSent();
    }

    public function test_c6_adapter_refuses_disabled_unapproved_and_invalid_hosts_before_network(): void
    {
        $this->configure();
        Http::fake();
        foreach ([['c6pix.enabled',false], ['c6pix.approval_reference',''], ['c6pix.token_url','https://example.org/token']] as [$key,$value]) {
            $original = config($key);
            config([$key=>$value]);
            try {
                (new C6Bank())->charge(str_repeat('a',32), '20.00', true);
                $this->fail('Configuração bancária inválida deveria bloquear o transporte.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('pix', $exception->errors());
            }
            config([$key=>$original]);
        }
        Http::assertNothingSent();
    }
}
