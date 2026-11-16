<?php
namespace Tests\Unit;

use App\Fiscal\{CertificateInspector,PfxCompatibility};
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Support\FiscalFixtures;
use Tests\TestCase;

class PfxCompatibilityTest extends TestCase
{
    private function legacy(array $fixture,?string $extra=null): string
    {
        $keys=[];$this->assertTrue(openssl_pkcs12_read($fixture['pfx'],$keys,$fixture['password']));
        $process=new Process(['/usr/bin/openssl','pkcs12','-export','-legacy','-passout','pass:'.$fixture['password']],null,null,$keys['pkey'].$keys['cert'].($extra??''),10);
        $process->run();$this->assertTrue($process->isSuccessful(),'Synthetic legacy fixture must be generated.');return $process->getOutput();
    }
    public function test_modern_pfx_is_unchanged_and_its_identity_remains_valid(): void
    {
        $f=FiscalFixtures::certificate();$this->assertSame($f['pfx'],app(PfxCompatibility::class)->normalize($f['pfx'],$f['password']));
        $meta=app(CertificateInspector::class)->inspect($f['pfx'],$f['password'],'11222333000181');$this->assertSame($f['fingerprint'],$meta['certificate_fingerprint']);
    }
    public function test_legacy_rc2_pfx_is_converted_in_memory_without_enabling_legacy_in_parent(): void
    {
        $f=FiscalFixtures::certificate();$legacy=$this->legacy($f);$keys=[];$this->assertFalse(@openssl_pkcs12_read($legacy,$keys,$f['password']));
        $modern=app(PfxCompatibility::class)->normalize($legacy,$f['password']);$this->assertTrue(openssl_pkcs12_read($modern,$keys,$f['password']));$this->assertSame($f['fingerprint'],openssl_x509_fingerprint($keys['cert'],'sha256'));
        $this->assertTrue(openssl_x509_check_private_key($keys['cert'],$keys['pkey']));$this->assertFalse(@openssl_pkcs12_read($legacy,$keys,$f['password']));
        $meta=app(CertificateInspector::class)->inspect($legacy,$f['password'],'11222333000181');$this->assertSame($f['fingerprint'],$meta['certificate_fingerprint']);
    }
    public function test_legacy_wrong_password_is_rejected_without_echoing_secrets(): void
    {
        $f=FiscalFixtures::certificate();$legacy=$this->legacy($f);
        try{app(PfxCompatibility::class)->normalize($legacy,'wrong-private-password');$this->fail('Wrong password accepted.');}catch(ValidationException $e){$this->assertArrayHasKey('certificate',$e->errors());$this->assertStringNotContainsString('wrong-private-password',$e->getMessage());}
    }
    public function test_legacy_certificate_from_another_cnpj_is_still_rejected(): void
    {
        $f=FiscalFixtures::certificate();$legacy=$this->legacy($f);
        try{app(CertificateInspector::class)->inspect($legacy,$f['password'],'07396600000160');$this->fail('Foreign identity accepted.');}catch(ValidationException $e){$this->assertStringContainsString('CNPJ',$e->errors()['certificate'][0]);}
    }
    public function test_legacy_conversion_preserves_additional_certificates(): void
    {
        $f=FiscalFixtures::certificate();$extra=FiscalFixtures::certificate('07396600000160');$keys=[];openssl_pkcs12_read($extra['pfx'],$keys,$extra['password']);$pem=$keys['cert'];
        $legacy=$this->legacy($f,$pem);$modern=app(PfxCompatibility::class)->normalize($legacy,$f['password']);openssl_pkcs12_read($modern,$keys,$f['password']);
        $this->assertNotEmpty($keys['extracerts']);$this->assertContains($extra['fingerprint'],array_map(fn($c)=>openssl_x509_fingerprint($c,'sha256'),$keys['extracerts']));
    }
    public function test_corrupt_and_oversized_files_are_rejected(): void
    {
        foreach(['invalid-pfx',str_repeat('x',2097153)] as $pfx){try{app(PfxCompatibility::class)->normalize($pfx,'test-password');$this->fail('Invalid file accepted.');}catch(ValidationException $e){$this->assertArrayHasKey('certificate',$e->errors());}}
    }
}
