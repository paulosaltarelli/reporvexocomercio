<?php

namespace Tests\Feature;

use App\Fiscal\FiscalTransportException;
use App\Fiscal\Nfse\Configuration;
use App\Fiscal\Nfse\DpsBuilder;
use App\Fiscal\Nfse\PrepareDps;
use App\Fiscal\Nfse\Readiness;
use App\Fiscal\Snapshot;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\NfseConfiguration;
use App\Models\NfseDocument;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Checkout;
use App\Services\TenantConnection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use NFePHP\Common\Certificate;
use NFePHP\Common\Signer;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\FiscalFixtures;
use Tests\TestCase;

/** Synthetic local DPS only: no certificate, code or response here establishes fiscal acceptance. */
class NfseTest extends TestCase
{
    private static bool $schemasReady = false;
    private bool $networkGuard = false;
    private Tenant $tenant;
    private User $user;
    private ProductGroup $group;
    private Product $service;
    private Customer $customer;
    private Sale $sale;
    private ?array $certificate = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (! env('VENDRA_TEST_PASSWORD')) {
            $this->markTestSkipped('Configure MySQL dedicado conforme docs/TESTES.md.');
        }
        config(['database.default' => 'central', 'database.connections.central' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'vendra_test_central', 'username' => 'vendra_test', 'password' => env('VENDRA_TEST_PASSWORD'), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true,
        ]]);
        DB::purge('central');
        $this->assertTestDatabase('central', 'vendra_test_central');
        if (! self::$schemasReady) {
            Artisan::call('migrate:fresh', ['--database' => 'central', '--force' => true]);
            foreach (['a', 'b'] as $slug) {
                app(TenantConnection::class)->connect(new Tenant($this->tenantAttributes($slug)));
                $this->assertTestDatabase('tenant', 'vendra_t_test_'.$slug);
                Artisan::call('migrate:fresh', ['--database' => 'tenant', '--path' => 'database/tenant-migrations', '--force' => true]);
            }
            self::$schemasReady = true;
        }
        $this->clearTestDatabase('central', 'vendra_test_central');
        foreach (['a', 'b'] as $slug) {
            $tenant = Tenant::create($this->tenantAttributes($slug));
            app(TenantConnection::class)->connect($tenant);
            $this->clearTestDatabase('tenant', 'vendra_t_test_'.$slug);
            if ($slug === 'a') { $this->tenant = $tenant; }
        }
        app(TenantConnection::class)->connect($this->tenant);
        Http::preventStrayRequests();
        Http::fake();
        $this->networkGuard = true;
        $this->user = User::create(['name'=>'Admin NFSe', 'email'=>'nfse@example.test', 'password'=>'test-password-123', 'role'=>'admin']);
        BusinessProfile::whereKey(1)->delete();
        BusinessProfile::forceCreate(['id'=>1, 'legal_name'=>'PRESTADOR SINTETICO SEM VALOR FISCAL', 'document'=>'11.222.333/0001-81', 'municipality_code'=>'3550308', 'state'=>'SP', 'tax_regime'=>'simples']);
        $this->group = ProductGroup::create(['name'=>'Serviços de teste', 'margin'=>'20']);
        $this->service = Product::create(['name'=>'SERVICO SINTETICO SEM VALOR FISCAL', 'sku'=>'NFSE-TEST', 'type'=>'service', 'controls_stock'=>false, 'reference_cost'=>'10.0000', 'sale_price'=>'100.01', 'product_group_id'=>$this->group->id]);
        $this->customer = Customer::create(['name'=>'TOMADOR SINTETICO', 'document'=>'52998224725']);
        $this->sale = $this->saleFor($this->service);
    }

    private function tenantAttributes(string $slug): array
    {
        return ['slug' => $slug, 'name' => 'Empresa '.$slug, 'active' => true, 'db_host' => '127.0.0.1', 'db_name' => 'vendra_t_test_'.$slug, 'db_username' => 'vendra_test', 'db_password' => env('VENDRA_TEST_PASSWORD')];
    }

    /** Clear committed fixtures only in the three explicitly named test databases. */
    private function clearTestDatabase(string $connectionName, string $expectedDatabase): void
    {
        $allowed = ['vendra_test_central', 'vendra_t_test_a', 'vendra_t_test_b'];
        $connection = DB::connection($connectionName);
        if (! in_array($expectedDatabase, $allowed, true) || $connection->getDatabaseName() !== $expectedDatabase || $connection->getConfig('username') !== 'vendra_test') {
            throw new \RuntimeException('Limpeza recusada: conexão não é um banco Vendra de teste.');
        }
        $actual = (array) $connection->selectOne('SELECT DATABASE() AS current_database');
        if ($actual['current_database'] !== $expectedDatabase) {
            throw new \RuntimeException('Limpeza recusada: banco ativo não corresponde ao banco de teste.');
        }
        $connection->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($connection->select('SHOW TABLES') as $tableRow) {
                $table = array_values((array) $tableRow)[0];
                if (in_array($table, ['migrations', 'cash_closure_locks'], true)) {
                    continue;
                }
                if (! preg_match('/^[a-zA-Z0-9_]+$/D', $table)) {
                    throw new \RuntimeException('Nome inesperado de tabela de teste.');
                }
                $connection->delete('DELETE FROM `'.$table.'`');
            }
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function assertTestDatabase(string $name, string $expected): void
    {
        $connection = DB::connection($name);
        $allowed = ['vendra_test_central', 'vendra_t_test_a', 'vendra_t_test_b'];
        if (! in_array($expected, $allowed, true) || $connection->getDatabaseName() !== $expected || $connection->getConfig('username') !== 'vendra_test' || $connection->selectOne('SELECT DATABASE() AS db')->db !== $expected) {
            throw new \RuntimeException('Fixture fiscal recusada fora dos bancos dedicados.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            if ($this->networkGuard) { Http::assertNothingSent(); }
            app(TenantConnection::class)->disconnect();
            DB::purge('central');
        }
        parent::tearDown();
    }

    private function connect(): void { app(TenantConnection::class)->connect($this->tenant); }
    private function login(?User $user = null): void { $this->withSession(['tenant_id'=>$this->tenant->id,'user_id'=>($user??$this->user)->id,'auth_version'=>0]); }
    private function fixture(): array { return $this->certificate ??= FiscalFixtures::certificate(); }

    /** Explicit schema fixtures, never defaults or claimed tax advice for a real taxpayer. */
    private function input(): array
    {
        return ['enabled'=>true,'national_issuer_confirmed'=>true,'municipality_code'=>'3550308','municipal_registration'=>null,
            'timezone'=>'America/Sao_Paulo','op_simp_nac'=>'3','reg_ap_trib_sn'=>'1','reg_esp_trib'=>'0',
            'service_national_code'=>'010701','service_municipal_code'=>null,'nbs'=>'115021100',
            'iss_mode'=>'municipal_parameterized','total_tax_mode'=>'not_informed','series'=>1,'next_number'=>1];
    }
    private function configured(): NfseConfiguration
    {
        $fixture=$this->fixture();
        return app(Configuration::class)->save($this->tenant,$this->input()+['certificate_password'=>$fixture['password']],$fixture['pfx'],$this->user->id);
    }
    private function saleFor(Product $product, array $extra = []): Sale
    {
        return app(Checkout::class)->complete(['request_key'=>(string)Str::uuid(),'customer_id'=>$this->customer->id,'paid'=>true,'payment_method'=>'cash',
            'items'=>array_merge([['kind'=>'product','id'=>$product->id,'quantity'=>'1','unit_price'=>$product->sale_price]],$extra)],$this->user->id);
    }
    private function prepare(?Sale $sale = null, ?string $key = null): NfseDocument
    {
        return app(PrepareDps::class)->prepare($this->tenant,($sale??$this->sale)->id,$this->user->id,['request_key'=>$key??(string)Str::uuid(),'competence'=>now()->subDay()->toDateString(),'operation_scope_confirmed'=>true]);
    }
    private function rejects(callable $action, array $fields = []): void
    {
        try { $action(); $this->fail('A operação inválida deveria ser rejeitada.'); }
        catch (ValidationException $exception) { foreach ($fields as $field) { $this->assertArrayHasKey($field,$exception->errors()); } $this->assertNotEmpty($exception->errors()); }
    }
    private function invalidXml(callable $action): void
    {
        try { $action(); $this->fail('XML adulterado deveria ser rejeitado.'); }
        catch (FiscalTransportException $exception) { $this->assertStringStartsWith('nfse_',$exception->getMessage()); }
    }
    private function denied(callable $action, int $status): void
    {
        try { $action(); $this->fail('Contexto não autorizado deveria ser rejeitado.'); }
        catch (HttpExceptionInterface $exception) { $this->assertSame($status,$exception->getStatusCode()); }
    }
    private function commercial(): array
    {
        $snapshot=[];
        foreach (['sales','sale_items','products','stock_movements','financial_entries','payments','documents','fiscal_documents','fiscal_outbox','fiscal_sequences'] as $table) {
            $snapshot[$table]=DB::connection('tenant')->table($table)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
        }
        return $snapshot;
    }
    private function xpath(string $xml): array
    {
        $dom=new \DOMDocument; $this->assertTrue($dom->loadXML($xml,LIBXML_NONET));
        $xp=new \DOMXPath($dom);$xp->registerNamespace('n',DpsBuilder::NS);$xp->registerNamespace('ds','http://www.w3.org/2000/09/xmldsig#');
        return [$dom,$xp];
    }
    private function resign(string $xml, callable $change, ?array $fixture = null): string
    {
        [$dom,$xp]=$this->xpath($xml);
        foreach ($xp->query('//ds:Signature') as $signature) { $signature->parentNode->removeChild($signature); }
        $change($dom,$xp);$fixture??=$this->fixture();
        return Signer::sign(Certificate::readPfx($fixture['pfx'],$fixture['password']),$dom->saveXML(),'infDPS','Id',OPENSSL_ALGO_SHA1,Signer::CANONICAL,'DPS');
    }

    public function test_configuration_requires_explicit_tax_codes_and_keeps_a1_private_and_company_pinned(): void
    {
        $missing=$this->input();unset($missing['service_national_code'],$missing['nbs']);
        $this->rejects(fn()=>app(Configuration::class)->save($this->tenant,$missing,null,$this->user->id),['service_national_code','nbs']);
        $this->assertSame(0,NfseConfiguration::count());
        $this->rejects(fn()=>app(Configuration::class)->save($this->tenant,$this->input(),null,$this->user->id),['certificate']);
        BusinessProfile::findOrFail(1)->update(['tax_regime'=>null]);
        $this->rejects(fn()=>app(Configuration::class)->save($this->tenant,$this->input(),null,$this->user->id),['op_simp_nac']);
        BusinessProfile::findOrFail(1)->update(['tax_regime'=>'simples']);
        $badPercentage=array_replace($this->input(),['total_tax_mode'=>'simples_percentage','total_tax_percent'=>'3.145']);
        $this->rejects(fn()=>app(Configuration::class)->save($this->tenant,$badPercentage,null,$this->user->id),['total_tax_percent']);
        $wrong=FiscalFixtures::certificate('11444777000161');
        $this->rejects(fn()=>app(Configuration::class)->save($this->tenant,$this->input()+['certificate_password'=>$wrong['password']],$wrong['pfx'],$this->user->id),['certificate']);
        $this->assertSame(0,NfseConfiguration::count());
        $configuration=$this->configured();$raw=DB::connection('tenant')->table('nfse_configurations')->value('secrets');
        $this->assertSame('restricted',$configuration->environment);
        $this->assertSame('010701',$configuration->parameters['service_national_code']);
        $this->assertStringNotContainsString($this->fixture()['password'],$raw);
        $this->assertStringNotContainsString(base64_encode($this->fixture()['pfx']),$raw);
        $this->assertArrayNotHasKey('secrets',$configuration->toArray());
        $this->assertSame($this->fixture()['fingerprint'],$configuration->certificate_fingerprint);
        $this->assertSame($this->fixture()['pfx'],base64_decode($configuration->secrets['pfx'],true));
        $sequence=DB::connection('tenant')->table('nfse_sequences')->first();
        $this->assertSame(1,(int)$sequence->next_number);
        $this->assertSame(0,DB::connection('tenant')->table('fiscal_outbox')->count());
    }

    public function test_preparation_signs_real_official_xsd_freezes_once_and_never_moves_commerce_or_transmits(): void
    {
        $configuration=$this->configured();$before=$this->commercial();$key=(string)Str::uuid();
        $document=$this->prepare(key:$key);
        $this->assertSame('draft_validated',$document->status);
        $this->assertSame('restricted',$document->environment);
        $this->assertSame('100.01',$document->snapshot['total']);
        $this->assertSame($this->tenant->id,$document->snapshot['tenant_id']);
        $this->assertSame(Snapshot::hash($document->snapshot),$document->snapshot_sha256);
        $this->assertSame(hash('sha256',$document->signed_xml),$document->xml_sha256);
        app(DpsBuilder::class)->validate($document->signed_xml,$document->snapshot);
        [$dom,$xp]=$this->xpath($document->signed_xml);
        $this->assertSame('2',$xp->evaluate('string(/n:DPS/n:infDPS/n:tpAmb)'));
        $this->assertSame('#'.$document->dps_id,$xp->evaluate('string(//ds:Reference/@URI)'));
        $this->assertSame('11222333000181',$xp->evaluate('string(/n:DPS/n:infDPS/n:prest/n:CNPJ)'));
        $this->assertSame('100.01',$xp->evaluate('string(/n:DPS/n:infDPS/n:valores/n:vServPrest/n:vServ)'));
        $this->assertTrue(Signer::isSigned($document->signed_xml,'infDPS'));
        $stored=DB::connection('tenant')->table('nfse_documents')->where('id',$document->id)->value('signed_xml');
        $this->assertStringNotContainsString('<DPS',$stored);
        $this->assertSame($document->id,$this->prepare(key:$key)->id);
        $this->assertSame($document->id,$this->prepare()->id);
        $this->assertSame(1,NfseDocument::count());
        $this->assertSame(2,(int)DB::connection('tenant')->table('nfse_sequences')->value('next_number'));
        $this->assertSame($before,$this->commercial());
        $report=app(Readiness::class)->inspect($configuration,$this->sale->fresh());
        $this->assertFalse($report['transport_ready']);
        $this->assertStringContainsString('não é uma NFS-e autorizada',$document->blocking_reason);
        $this->assertArrayNotHasKey('protocol',$document->getAttributes());
        $this->assertArrayNotHasKey('access_key',$document->getAttributes());
        $second=$this->saleFor($this->service);
        $this->rejects(fn()=>$this->prepare($second,$key),['request_key']);
        $secondDocument=$this->prepare($second);
        $this->assertSame(2,(int)$secondDocument->number);
        $this->assertNotSame($document->dps_id,$secondDocument->dps_id);
        $this->rejects(fn()=>app(Configuration::class)->save($this->tenant,$this->input(),null,$this->user->id),['next_number']);
        $this->assertSame(3,(int)DB::connection('tenant')->table('nfse_sequences')->value('next_number'));
    }

    public function test_service_classification_is_frozen_and_a_later_catalog_conversion_cannot_turn_goods_into_dps(): void
    {
        $configuration=$this->configured();
        $goods=Product::create(['name'=>'MERCADORIA TESTE','sku'=>'NFSE-GOODS','type'=>'product','controls_stock'=>false,'reference_cost'=>'1','sale_price'=>'10','product_group_id'=>$this->group->id]);
        $sale=$this->saleFor($goods);$this->assertSame('product',$sale->items()->first()->fiscal_kind);
        $goods->update(['type'=>'service','controls_stock'=>false]);
        $this->assertFalse(app(Readiness::class)->inspect($configuration,$sale->fresh())['draft_ready']);
        $this->rejects(fn()=>$this->prepare($sale),['nfse']);
        DB::connection('tenant')->table('sale_items')->where('sale_id',$this->sale->id)->update(['fiscal_kind'=>'product']);
        $this->rejects(fn()=>$this->prepare(),['nfse']);
        $this->assertSame(0,NfseDocument::count());
        $this->assertSame(1,(int)DB::connection('tenant')->table('nfse_sequences')->value('next_number'));
    }

    public function test_mixed_discounted_cancelled_and_production_cases_are_blocked_without_reserving_dps_numbers(): void
    {
        $configuration=$this->configured();
        $goods=Product::create(['name'=>'ITEM TESTE','sku'=>'NFSE-MIX','type'=>'product','controls_stock'=>false,'reference_cost'=>'1','sale_price'=>'10','product_group_id'=>$this->group->id]);
        $mixed=$this->saleFor($this->service,[['kind'=>'product','id'=>$goods->id,'quantity'=>'1','unit_price'=>'10']]);
        $this->rejects(fn()=>$this->prepare($mixed),['nfse']);
        $this->group->update(['discount_enabled'=>true,'max_discount_percent'=>'20']);
        $discounted=app(Checkout::class)->complete(['request_key'=>(string)Str::uuid(),'paid'=>true,'payment_method'=>'cash','items'=>[['kind'=>'product','id'=>$this->service->id,'quantity'=>'1','unit_price'=>'100.01','discount_percent'=>'10']]],$this->user->id);
        $this->rejects(fn()=>$this->prepare($discounted),['nfse']);
        DB::connection('tenant')->table('sales')->where('id',$this->sale->id)->update(['status'=>'cancelled']);
        $this->rejects(fn()=>$this->prepare(),['nfse']);
        DB::connection('tenant')->table('sales')->where('id',$this->sale->id)->update(['status'=>'completed']);
        $this->rejects(fn()=>app(Configuration::class)->save($this->tenant,$this->input()+['environment'=>'production'],null,$this->user->id),['environment']);
        $configuration->update(['environment'=>'production']);
        $this->rejects(fn()=>$this->prepare(),['nfse']);
        $this->assertSame(0,NfseDocument::count());
        $this->assertSame(1,(int)DB::connection('tenant')->table('nfse_sequences')->value('next_number'));
    }

    public function test_frozen_xml_rejects_resigned_content_foreign_certificate_production_and_unsafe_transforms(): void
    {
        $this->configured();$document=$this->prepare();$snapshot=$document->snapshot;$xml=$document->signed_xml;
        $validator=app(DpsBuilder::class);
        foreach (['/n:DPS/n:infDPS/n:valores/n:vServPrest/n:vServ'=>'100.02','/n:DPS/n:infDPS/n:serv/n:cServ/n:xDescServ'=>'OUTRA DESCRICAO SINTETICA','/n:DPS/n:infDPS/n:toma/n:xNome'=>'OUTRO TOMADOR','/n:DPS/n:infDPS/n:prest/n:regTrib/n:regEspTrib'=>'1'] as $path=>$value) {
            $altered=$this->resign($xml,function($dom,$xp)use($path,$value){$node=$xp->query($path)->item(0);$this->assertNotNull($node);$node->nodeValue=$value;});
            $this->assertTrue(Signer::isSigned($altered,'infDPS'));
            $this->invalidXml(fn()=>$validator->validate($altered,$snapshot));
        }
        $foreign=$this->resign($xml,static function(){},FiscalFixtures::certificate());
        $this->assertTrue(Signer::isSigned($foreign,'infDPS'));
        $this->invalidXml(fn()=>$validator->validate($foreign,$snapshot));
        $production=$snapshot;$production['environment']='production';
        $this->invalidXml(fn()=>$validator->build($production,$this->fixture()['pfx'],$this->fixture()['password']));
        $wrongNamespace=str_replace(DpsBuilder::NS,'urn:vendra:invalid-nfse-namespace',$xml);
        $this->assertNotSame($xml,$wrongNamespace);
        $this->invalidXml(fn()=>$validator->validate($wrongNamespace,$snapshot));
        $doctype=str_replace('<DPS ','<!DOCTYPE DPS [<!ENTITY blocked SYSTEM "file:///vendra-nfse-test-not-present">]><DPS ',$xml);
        $this->assertNotSame($xml,$doctype);
        $this->invalidXml(fn()=>$validator->validate($doctype,$snapshot));
        // Re-sign SignedInfo after declaring an unsupported transform. Its RSA
        // signature is valid, but the declared XMLDSig pipeline must be rejected.
        [$dom,$xp]=$this->xpath($xml);
        $xp->query('//ds:Transform')->item(1)->setAttribute('Algorithm','http://www.w3.org/2001/10/xml-exc-c14n#');
        $info=$xp->query('//ds:SignedInfo')->item(0);$keys=[];$fixture=$this->fixture();
        $this->assertTrue(openssl_pkcs12_read($fixture['pfx'],$keys,$fixture['password']));
        $this->assertTrue(openssl_sign($info->C14N(false,false),$signature,$keys['pkey'],OPENSSL_ALGO_SHA1));
        $xp->query('//ds:SignatureValue')->item(0)->nodeValue=base64_encode($signature);
        $this->assertSame(1,openssl_verify($info->C14N(false,false),$signature,$keys['cert'],OPENSSL_ALGO_SHA1));
        $this->invalidXml(fn()=>$validator->validate($dom->saveXML(),$snapshot));
    }

    public function test_http_download_pins_frozen_content_and_tenant_and_never_exposes_pfx(): void
    {
        $this->configured();$document=$this->prepare();$original=$document->signed_xml;$this->login();
        $this->get('/app/a/nfse/documents/'.$document->id.'/xml')->assertOk()->assertHeader('X-Content-Type-Options','nosniff')->assertDontSee($this->fixture()['password'],false)->assertDontSee(base64_encode($this->fixture()['pfx']),false);
        $this->connect();
        $altered=$this->resign($original,function($dom,$xp){$xp->query('/n:DPS/n:infDPS/n:toma/n:xNome')->item(0)->nodeValue='TOMADOR ALTERADO';});
        DB::connection('tenant')->table('nfse_documents')->where('id',$document->id)->update(['signed_xml'=>Crypt::encryptString($altered),'xml_sha256'=>hash('sha256',$altered)]);
        $this->get('/app/a/nfse/documents/'.$document->id.'/xml')->assertStatus(409);
        $this->connect();
        DB::connection('tenant')->table('nfse_documents')->where('id',$document->id)->update(['signed_xml'=>Crypt::encryptString($original),'xml_sha256'=>hash('sha256',$original)]);
        $bad=NfseDocument::findOrFail($document->id);$snapshot=$bad->snapshot;$snapshot['tenant_id']=$this->tenant->id+1000;
        DB::connection('tenant')->table('nfse_documents')->where('id',$document->id)->update(['snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR),'snapshot_sha256'=>Snapshot::hash($snapshot)]);
        $this->get('/app/a/nfse/documents/'.$document->id)->assertStatus(409);
        $this->connect();
        $other=Tenant::where('slug','b')->firstOrFail();
        app(TenantConnection::class)->connect($other);
        $otherUser=User::create(['name'=>'Admin B','email'=>'nfse-b@example.test','password'=>'test-password-123','role'=>'admin']);
        $this->withSession(['tenant_id'=>$other->id,'user_id'=>$otherUser->id,'auth_version'=>0]);
        $this->get('/app/b/nfse/documents/'.$document->id.'/xml')->assertNotFound();
        app(TenantConnection::class)->connect($other);
        $this->denied(fn()=>app(PrepareDps::class)->verify($document,$this->tenant,$this->user->id),403);
    }

    public function test_http_admin_operator_separation_and_invalid_configuration_do_not_flash_secrets(): void
    {
        $this->login();$this->get('/app/a/nfse')->assertOk();
        $secret='SYNTHETIC-DO-NOT-FLASH';
        $this->from('/app/a/nfse')->post('/app/a/nfse/config',['certificate_password'=>$secret])->assertSessionHasErrors(['service_national_code','nbs'])->assertSessionMissing('_old_input.certificate_password');
        $this->connect();
        $fixture=$this->fixture();
        $this->post('/app/a/nfse/config',$this->input()+['certificate_password'=>$fixture['password'],'certificate'=>UploadedFile::fake()->createWithContent('synthetic-company.pfx',$fixture['pfx'])])->assertSessionHasNoErrors()->assertRedirect();
        $this->connect();
        $this->assertSame(1,NfseConfiguration::count());
        $this->withSession(['_old_input'=>['_fiscal_form'=>'nfe55','series'=>'7']]);
        $page=$this->get('/app/a/nfse')->assertOk();
        $dom=new \DOMDocument();@$dom->loadHTML($page->getContent());
        $xpath=new \DOMXPath($dom);
        $this->assertSame('1',$xpath->query('//input[@id="nfse_series"]')->item(0)->getAttribute('value'));
        $this->withSession(['_old_input'=>['_fiscal_form'=>'nfse','series'=>'7']]);
        $page=$this->get('/app/a/nfse')->assertOk();
        $dom=new \DOMDocument();@$dom->loadHTML($page->getContent());
        $xpath=new \DOMXPath($dom);
        $this->assertSame('7',$xpath->query('//input[@id="nfse_series"]')->item(0)->getAttribute('value'));
        $this->assertSame('nfse',$xpath->query('//input[@name="_fiscal_form"]')->item(0)->getAttribute('value'));
        $this->withSession(['_old_input'=>[]]);
        $this->connect();
        $operator=User::create(['name'=>'Operator','email'=>'nfse-operator@example.test','password'=>'test-password-123','role'=>'operator']);
        $this->login($operator);
        $this->get('/app/a/nfse')->assertForbidden();
        $this->post('/app/a/nfse/sales/'.$this->sale->id.'/prepare',['request_key'=>(string)Str::uuid(),'competence'=>now()->subDay()->toDateString(),'operation_scope_confirmed'=>true])->assertForbidden();
        $this->connect();
        $this->denied(fn()=>app(Configuration::class)->save($this->tenant,$this->input(),null,$operator->id),403);
        $this->login();
        $this->postJson('/app/a/nfse/sales/'.$this->sale->id.'/prepare',['request_key'=>(string)Str::uuid(),'competence'=>now()->subDay()->toDateString(),'operation_scope_confirmed'=>true,'environment'=>'production'])->assertUnprocessable()->assertJsonValidationErrors('environment');
        $this->connect();
        $this->assertSame(0,NfseDocument::count());
        $this->assertSame(1,(int)DB::connection('tenant')->table('nfse_sequences')->value('next_number'));
    }

    public function test_prepared_dps_remains_immutable_and_all_transmission_routes_are_explicitly_blocked(): void
    {
        $this->configured();$document=$this->prepare();$before=$this->commercial();$this->login();
        $this->get('/app/a/nfse/documents/'.$document->id)->assertOk()->assertSee('DPS',false);
        $this->postJson('/app/a/nfse/documents/'.$document->id.'/issue')->assertUnprocessable()->assertJsonValidationErrors('nfse');
        $this->postJson('/app/a/nfse/documents/'.$document->id.'/consult')->assertUnprocessable()->assertJsonValidationErrors('nfse');
        $this->connect();
        $this->assertSame('draft_validated',$document->fresh()->status);
        $this->assertSame($before,$this->commercial());
        try { $document->update(['status'=>'authorized']);$this->fail('DPS local não pode ser promovida a autorização.'); }
        catch (\LogicException $exception) { $this->assertStringContainsString('imutável',$exception->getMessage()); }
        $this->assertSame(2,(int)DB::connection('tenant')->table('nfse_sequences')->value('next_number'));
        $this->assertSame(0,DB::connection('tenant')->table('fiscal_outbox')->count());
    }
}
