<?php

namespace Tests\Feature;

use App\Fiscal\CompanyFiscalConfiguration;
use App\Fiscal\PendingNfceCertificate;
use App\Models\BusinessProfile;
use App\Models\FiscalCredential;
use App\Models\Tenant;
use App\Services\TenantConnection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\FiscalFixtures;

trait PreparedFiscalCertificateCases
{
    private function pendingCertificateProfile(): void
    {
        BusinessProfile::forceCreate(['id' => 1, 'legal_name' => 'Fixture', 'document' => '11.222.333/0001-81', 'tax_regime' => 'simples']);
    }

    private function certificateActivation(): array
    {
        return ['intent' => 'activate', 'environment' => 'homologation', 'series' => 1, 'next_number' => 1,
            'timezone' => 'America/Sao_Paulo', 'crt' => '1', 'natureza_operacao' => 'VENDA TESTE',
            'identification_limit' => '10000.00', 'cancellation_minutes' => 30, 'uf_validation_reference' => 'Teste',
            'csc_required' => 1, 'csc_id' => '01', 'csc' => 'fixture-csc-prepared'];
    }

    private function runCertificateStage(string $bytes, string $document = '11222333000181'): int
    {
        $stream = fopen('php://memory', 'w+b');
        try {
            fwrite($stream, $bytes);
            rewind($stream);
            $input = new ArrayInput(['tenant_id' => (string) $this->tenant->id, 'cnpj' => $document]);
            $input->setStream($stream);
            $output = new BufferedOutput;
            $status = Artisan::all()['vendra:fiscal-stage-pfx']->run($input, $output);
            if ($bytes !== '') {
                $this->assertStringNotContainsString($bytes, $output->fetch());
            }
            return $status;
        } finally {
            fclose($stream);
            app(TenantConnection::class)->connect($this->tenant);
        }
    }

    public function test_prepared_certificate_cli_and_https_form_activate_once_without_an_upload(): void
    {
        $disk = Storage::fake('fiscal');
        Http::preventStrayRequests();
        $this->pendingCertificateProfile();
        $fixture = FiscalFixtures::certificate();
        $this->assertSame(1, $this->runCertificateStage(''));
        $this->assertSame(1, $this->runCertificateStage(str_repeat('x', PendingNfceCertificate::MAX_BYTES + 1)));
        $this->assertSame(1, $this->runCertificateStage($fixture['pfx'], '04252011000110'));
        $this->assertEmpty($disk->allFiles());
        $this->assertSame(0, $this->runCertificateStage($fixture['pfx']));

        $files = array_values(array_filter($disk->allFiles(), fn ($path) => str_ends_with($path, '.enc')));
        $this->assertCount(1, $files);
        $ciphertext = $disk->get($files[0]);
        $this->assertStringNotContainsString($fixture['pfx'], $ciphertext);
        $this->assertStringNotContainsString(base64_encode($fixture['pfx']), $ciphertext);
        $this->assertSame('private', $disk->getVisibility($files[0]));
        $this->assertSame(0, FiscalCredential::count());
        $revision = hash('sha256', $ciphertext);
        $this->login();
        $this->get('https://localhost/app/a/fiscal/company')->assertOk()->assertSee('Usar o certificado A1 já entregue')->assertSee($revision)
            ->assertDontSee($ciphertext)->assertDontSee($files[0])->assertDontSee(base64_encode($fixture['pfx']));
        $this->get('http://localhost/app/a/fiscal/company')->assertOk()->assertDontSee($revision);
        $url = 'https://localhost/app/a/business/fiscal';
        $input = $this->certificateActivation() + ['prepared_certificate' => $revision, 'certificate_password' => $fixture['password']];
        $this->post('http://localhost/app/a/business/fiscal', $input)->assertForbidden();
        $this->post($url, array_replace($input, ['environment' => 'production']))->assertSessionHasErrors('environment');
        $this->post($url, array_replace($input, ['intent' => 'draft']))->assertSessionHasErrors('prepared_certificate');
        $this->post($url, $input + ['tenant_id' => $this->tenant->id, 'certificate_path' => '/untrusted'])->assertSessionHasErrors(['tenant_id', 'certificate_path']);
        $this->post($url, array_replace($input, ['certificate_password' => 'fixture-wrong']))->assertSessionHasErrors('certificate')
            ->assertSessionMissing('_old_input.certificate_password')->assertSessionMissing('_old_input.csc')->assertSessionMissing('_old_input.csc_id');
        $this->post($url, array_replace($input, ['csc' => '']))->assertSessionHasErrors('csc');
        $this->assertSame($ciphertext, $disk->get($files[0]));
        $this->post($url, $input)->assertRedirect()->assertSessionHasNoErrors();
        $disk->assertMissing($files[0]);
        app(TenantConnection::class)->connect($this->tenant);
        $credential = FiscalCredential::firstOrFail();
        $this->assertSame($fixture['fingerprint'], $credential->certificate_fingerprint);
        $this->assertSame('000001', $credential->secrets['csc_id']);
        $this->assertSame('fixture-csc-prepared', $credential->secrets['csc']);
        $this->assertStringNotContainsString('fixture-csc-prepared', $credential->getRawOriginal('secrets'));
        $this->post($url, $input)->assertSessionHasErrors('certificate');
        Http::assertNothingSent();
    }

    public function test_prepared_certificate_checks_binding_revision_upload_priority_and_admin_role(): void
    {
        $disk = Storage::fake('fiscal');
        $this->pendingCertificateProfile();
        $prepared = app(PendingNfceCertificate::class);
        $fixture = FiscalFixtures::certificate();
        $differentIssuer = FiscalFixtures::certificate('04252011000110');
        $this->assertSame(0, $this->runCertificateStage($differentIssuer['pfx']));
        $first = $prepared->revision($this->tenant, '11222333000181');
        $url = 'https://localhost/app/a/business/fiscal';
        $input = $this->certificateActivation() + ['prepared_certificate' => $first, 'certificate_password' => $fixture['password']];
        $this->login();
        $this->post($url, $input)->assertSessionHasErrors('certificate');
        $this->assertStringContainsString('CNPJ', session('errors')->first('certificate'));
        app(TenantConnection::class)->connect($this->tenant);
        $this->assertSame(0, FiscalCredential::count());
        $this->assertSame(0, $this->runCertificateStage($fixture['pfx']));
        $current = $prepared->revision($this->tenant, '11222333000181');
        $this->assertNotSame($first, $current);
        $this->post($url, $input)->assertSessionHasErrors('certificate');
        app(TenantConnection::class)->connect($this->tenant);
        BusinessProfile::whereKey(1)->update(['document' => '04252011000110']);
        $this->get('https://localhost/app/a/fiscal/company')->assertOk()->assertDontSee('Usar o certificado A1 já entregue');
        $input['prepared_certificate'] = $current;
        $this->post($url, $input)->assertSessionHasErrors('certificate');
        app(TenantConnection::class)->connect($this->tenant);
        BusinessProfile::whereKey(1)->update(['document' => '11222333000181']);
        $upload = FiscalFixtures::certificate();
        $this->post($url, $input + ['certificate' => UploadedFile::fake()->createWithContent('fixture.pfx', $upload['pfx'])])->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->tenant);
        $this->assertSame($upload['fingerprint'], FiscalCredential::firstOrFail()->certificate_fingerprint);
        $this->assertNull($prepared->revision($this->tenant, '11222333000181'));
        $this->assertSame(0, $this->runCertificateStage($fixture['pfx']));
        $current = $prepared->revision($this->tenant, '11222333000181');
        $input['prepared_certificate'] = $current;

        $acl = app(\App\Access\AccessProfiles::class);
        $profile = $acl->save(['version' => 0, 'name' => 'Fiscal delegado', 'permissions' => ['fiscal.manage']], $this->user->id);
        DB::connection('tenant')->transaction(fn () => $acl->assign($this->user->id, 'operator', $profile, $this->user->id));
        $this->user->update(['role' => 'operator']);
        $this->get('https://localhost/app/a/fiscal/company')->assertOk()->assertDontSee($current);
        $this->post($url, $input)->assertForbidden();
        $this->post($url, ['intent' => 'certificate', 'environment' => 'homologation', 'prepared_certificate' => $current, 'certificate_password' => $fixture['password']])->assertForbidden();
        app(TenantConnection::class)->connect($this->tenant);
        $this->assertSame($current, $prepared->revision($this->tenant, '11222333000181'));

        $other = Tenant::where('slug', 'b')->firstOrFail();
        app(TenantConnection::class)->connect($other);
        $this->pendingCertificateProfile();
        $this->assertNull($prepared->revision($other, '11222333000181'));
        $otherCert = FiscalFixtures::certificate();
        $prepared->stage($other, '11222333000181', $otherCert['pfx']);
        // Even copying a valid encrypted file between local tenant directories cannot rebind it.
        $a = collect($disk->allFiles('tenants/'.$this->tenant->id))->first(fn ($file) => str_ends_with($file, '.enc'));
        $b = collect($disk->allFiles('tenants/'.$other->id))->first(fn ($file) => str_ends_with($file, '.enc'));
        $disk->put($b, $disk->get($a));
        $this->expectException(ValidationException::class);
        DB::connection('tenant')->transaction(fn () => $prepared->forActivation($other, '11222333000181', $current));
    }

    public function test_prepared_certificate_survives_outer_rollback_and_concurrent_claim(): void
    {
        Storage::fake('fiscal');
        $this->pendingCertificateProfile();
        $fixture = FiscalFixtures::certificate();
        $this->assertSame(0, $this->runCertificateStage($fixture['pfx']));
        $prepared = app(PendingNfceCertificate::class);
        $revision = $prepared->revision($this->tenant, '11222333000181');
        $input = $this->certificateActivation();
        $db = DB::connection('tenant');
        $db->beginTransaction();
        try {
            app(CompanyFiscalConfiguration::class)->save($this->tenant, $input, ['certificate_password' => $fixture['password'], 'csc' => $input['csc'], 'csc_id' => '01'], null, $revision);
            $this->assertSame($revision, $prepared->revision($this->tenant, '11222333000181'));
            try {
                $prepared->forActivation($this->tenant, '11222333000181', $revision);
                $this->fail('Uma segunda ativação adquiriu o mesmo arquivo em uso.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('ocupado', $e->errors()['certificate'][0]);
            }
        } finally {
            $db->rollBack();
        }
        $this->assertSame(0, FiscalCredential::count());
        $this->assertSame($revision, $prepared->revision($this->tenant, '11222333000181'));
        $this->assertSame(0, $this->runCertificateStage($fixture['pfx']));
        $this->assertNotSame($revision, $prepared->revision($this->tenant, '11222333000181'));
    }

    public function test_prepared_certificate_password_alone_enrolls_a1_before_csc_series_or_tax_regime(): void
    {
        Storage::fake('fiscal');
        Http::preventStrayRequests();
        $this->pendingCertificateProfile();
        BusinessProfile::whereKey(1)->update(['tax_regime' => null]);
        $fixture = FiscalFixtures::certificate();
        $this->assertSame(0, $this->runCertificateStage($fixture['pfx']));
        $revision = app(PendingNfceCertificate::class)->revision($this->tenant, '11222333000181');
        $this->login();
        $page = 'https://localhost/app/a/fiscal/company';
        $url = 'https://localhost/app/a/business/fiscal';
        $this->get($page)->assertOk()->assertSee('id="certificado-digital-a1"', false)->assertSee('Validar e guardar certificado');
        $input = ['intent' => 'certificate', 'environment' => 'homologation', 'prepared_certificate' => $revision, 'certificate_password' => $fixture['password']];
        $this->post($url, array_replace($input, ['certificate_password' => 'fixture-wrong']))->assertSessionHasErrors('certificate')->assertSessionMissing('_old_input.certificate_password');
        $this->post('http://localhost/app/a/business/fiscal', $input)->assertForbidden();
        $this->post($url, array_replace($input, ['environment' => 'production']))->assertSessionHasErrors('environment');
        $this->post($url, $input + ['csc' => 'irrelevant', 'series' => 1])->assertSessionHasErrors(['csc','series']);
        $this->post($url, $input)->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->tenant);
        $credential = FiscalCredential::firstOrFail();
        $issuer = \App\Models\FiscalIssuer::firstOrFail();
        $this->assertSame($fixture['fingerprint'], $credential->certificate_fingerprint);
        $this->assertSame('11222333000181', $credential->certificate_document);
        $this->assertSame($fixture['pfx'], base64_decode($credential->secrets['pfx'], true));
        $this->assertSame($fixture['password'], $credential->secrets['certificate_password']);
        $this->assertStringNotContainsString($fixture['password'], $credential->getRawOriginal('secrets'));
        $this->assertArrayNotHasKey('csc', $credential->secrets);
        $this->assertNull($credential->validated_hash);
        $this->assertFalse($issuer->enabled);
        $this->assertNull($issuer->validated_configuration);
        $this->assertNull(app(PendingNfceCertificate::class)->revision($this->tenant, '11222333000181'));
        foreach (['fiscal_sequences','fiscal_settings','fiscal_model_configurations','fiscal_documents','fiscal_outbox'] as $table) {
            $this->assertSame(0, DB::connection('tenant')->table($table)->count(), $table);
        }
        $this->get($page)->assertOk()->assertSee('Certificado A1 cadastrado')->assertDontSee($fixture['password'])
            ->assertDontSee(base64_encode($fixture['pfx']))->assertDontSee('Validar configuração de homologação');
        $this->post('https://localhost/app/a/business/fiscal/validate')->assertSessionHasErrors('fiscal');
        app(TenantConnection::class)->connect($this->tenant);
        BusinessProfile::whereKey(1)->update(['tax_regime' => 'simples']);
        $this->post($url, $this->certificateActivation())->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->tenant);
        $this->assertTrue($issuer->fresh()->enabled);
        $this->assertSame($fixture['fingerprint'], $credential->fresh()->certificate_fingerprint);
        $this->assertNotNull(DB::connection('tenant')->table('fiscal_sequences')->where('model', 65)->first());
        Http::assertNothingSent();
    }

    public function test_prepared_certificate_enrollment_preserves_live_model_settings_and_existing_a1(): void
    {
        Storage::fake('fiscal');
        [$issuer, $cert] = $this->configuredFiscal();
        $nf55 = app(\App\Fiscal\NfeCompanyConfiguration::class);
        $nf55->save($this->tenant, $this->nfe55Configuration($issuer->validated_configuration['crt']), [], null);
        $nf55->validateLocal($this->tenant);
        $credential = FiscalCredential::where('issuer_id', $issuer->id)->firstOrFail();
        $oldIssuer = $issuer->fresh()->getRawOriginal();
        $old = $credential->getRawOriginal();
        $old55 = $nf55->forIssuer($issuer)->getRawOriginal();
        $this->assertSame(0, $this->runCertificateStage($cert['pfx']));
        $revision = app(PendingNfceCertificate::class)->revision($this->tenant, '11222333000181');
        $this->login();
        $url = 'https://localhost/app/a/business/fiscal';
        $input = ['intent' => 'certificate', 'environment' => 'homologation', 'certificate_password' => $cert['password'], 'prepared_certificate' => $revision];
        $other = FiscalFixtures::certificate();
        $this->post($url, $input + ['certificate' => UploadedFile::fake()->createWithContent('fixture.pfx', $other['pfx'])])->assertSessionHasErrors('certificate');
        $this->post($url, $input)->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->tenant);
        $this->assertSame($old, $credential->fresh()->getRawOriginal());
        $this->assertSame($oldIssuer, $issuer->fresh()->getRawOriginal());
        $this->assertSame($old55, $nf55->forIssuer($issuer)->getRawOriginal());
        $this->assertSame(2, DB::connection('tenant')->table('fiscal_sequences')->count());
        $this->assertNull(app(PendingNfceCertificate::class)->revision($this->tenant, '11222333000181'));
    }

    public function test_prepared_certificate_stale_form_with_explicit_upload_keeps_newer_preparation(): void
    {
        Storage::fake('fiscal');
        $this->pendingCertificateProfile();
        $fixture = FiscalFixtures::certificate();
        $this->assertSame(0, $this->runCertificateStage($fixture['pfx']));
        $prepared = app(PendingNfceCertificate::class);
        $old = $prepared->revision($this->tenant, '11222333000181');
        $this->assertSame(0, $this->runCertificateStage($fixture['pfx']));
        $new = $prepared->revision($this->tenant, '11222333000181');
        $this->assertNotSame($old, $new);
        $this->login();
        $upload = FiscalFixtures::certificate();
        $this->post('https://localhost/app/a/business/fiscal', ['intent' => 'certificate', 'environment' => 'homologation',
            'certificate' => UploadedFile::fake()->createWithContent('fixture.pfx', $upload['pfx']),
            'prepared_certificate' => $old, 'certificate_password' => $upload['password']])->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->tenant);
        $this->assertSame($upload['fingerprint'], FiscalCredential::firstOrFail()->certificate_fingerprint);
        $this->assertSame($new, $prepared->revision($this->tenant, '11222333000181'));
    }
}
