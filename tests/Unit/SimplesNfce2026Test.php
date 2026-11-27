<?php

namespace Tests\Unit;

use App\Fiscal\TaxProfile;
use App\Fiscal\FiscalTransportException;
use App\Fiscal\SefazProvider;
use App\Fiscal\SefazTransport;
use App\Models\FiscalCredential;
use App\Models\FiscalIssuer;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;
use NFePHP\Common\Certificate;
use NFePHP\Common\Keys;
use NFePHP\Common\Signer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FiscalFixtures;
use Tests\TestCase;

class SimplesNfce2026Test extends TestCase
{
    public function test_conventional_sale_uses_the_issuers_date_and_never_builds_regular_ibs_cbs(): void
    {
        $tax = new TaxProfile;
        $parameters = $tax->validate(FiscalFixtures::simplesNfce2026());
        $this->assertSame('2026-01-01', $parameters['valid_from']);
        $this->assertSame('2026-12-31', $parameters['valid_until']);
        $fields = $tax->fields($parameters, '39.90', '1', new DateTimeImmutable('2026-12-31T23:59:59-03:00'));
        $this->assertSame('5102', $fields['cfop']);
        $this->assertSame('102', $fields['icms_situacao_tributaria']);
        $this->assertSame('99', $fields['pis_situacao_tributaria']);
        $this->assertSame('0.00', $fields['cofins_valor']);
        $this->assertSame('3.99', $fields['valor_total_tributos']);
        $this->assertArrayNotHasKey('ibs_cbs_situacao_tributaria', $fields);
    }

    #[DataProvider('incompatibleIssues')]
    public function test_a_new_document_requires_a_compatible_regime_model_and_date(string $crt, ?string $date, bool $legacy): void
    {
        $this->expectException(ValidationException::class);
        $parameters = $legacy ? FiscalFixtures::rule() : FiscalFixtures::simplesNfce2026();
        (new TaxProfile)->fields($parameters, '39.90', $crt, $date === null ? null : new DateTimeImmutable($date));
    }

    public static function incompatibleIssues(): array
    {
        return [
            'outro modelo' => ['1', null, false],
            'CRT 3' => ['3', '2026-11-27', false],
            'antes da vigência' => ['1', '2025-12-31', false],
            'virada local de 2027' => ['1', '2027-01-01T00:00:00-03:00', false],
            'perfil regular legado em novembro' => ['1', '2026-11-27', true],
            'perfil regular legado em março' => ['1', '2027-03-01', true],
        ];
    }

    public function test_configuration_requires_reviewed_tax_codes_and_rejects_incompatible_rates(): void
    {
        foreach (['cfop' => '5405', 'icms_code' => '500', 'pis_code' => '07', 'cofins_rate' => '0.9',
            'rtc_mode' => 'standard', 'valid_until' => '2027-01-01'] as $field => $value) {
            try {
                (new TaxProfile)->validate(array_replace(FiscalFixtures::simplesNfce2026(), [$field => $value]));
                $this->fail('Campo incompatível aceito: '.$field);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
    }

    #[DataProvider('legacySignedSales')]
    public function test_frozen_xml_can_be_consulted_but_an_incompatible_sale_never_reaches_authorization(string $year, string $group): void
    {
        $issuer = (new FiscalIssuer)->forceFill(['id' => 1, 'document' => '11222333000181', 'environment' => 'homologation', 'provider' => 'sefaz_direct']);
        $certificate = FiscalFixtures::certificate();
        $credential = (new FiscalCredential)->forceFill(['issuer_id' => 1, 'certificate_fingerprint' => $certificate['fingerprint']]);
        $key = Keys::build(31, substr($year, 2), 11, $issuer->document, 65, 1, 1, 1, '27340162');
        $xml = '<NFe xmlns="http://www.portalfiscal.inf.br/nfe"><infNFe versao="4.00" Id="NFe'.$key.'"><ide>'
            .'<mod>65</mod><serie>1</serie><nNF>1</nNF><dhEmi>'.$year.'-11-27T10:00:00-03:00</dhEmi><tpEmis>1</tpEmis><tpAmb>2</tpAmb></ide>'
            .'<emit><CNPJ>'.$issuer->document.'</CNPJ><CRT>1</CRT></emit><det nItem="1"><imposto>'.$group.'</imposto></det></infNFe></NFe>';
        $signed = Signer::sign(Certificate::readPfx($certificate['pfx'], $certificate['password']), $xml, 'infNFe', 'Id', OPENSSL_ALGO_SHA1, Signer::CANONICAL);
        $payload = ['_signed_xml' => $signed, '_access_key' => $key, 'cnpj_emitente' => $issuer->document, 'serie' => '1', 'numero' => '1'];
        $transport = \Mockery::mock(SefazTransport::class);
        $transport->shouldNotReceive('authorize');
        $transport->shouldReceive('consult')->once()->with($key)->andThrow(new FiscalTransportException('consulta_simulada'));
        $provider = new SefazProvider($issuer, $credential, $payload, $transport);
        try {
            $provider->issue('teste-local', $payload);
            $this->fail('A validação local devia ter barrado o XML.');
        } catch (FiscalTransportException $exception) {
            $this->assertSame('nfce_simples_tax_layout_incompatible', $exception->getMessage());
        }
        $this->expectExceptionMessage('consulta_simulada');
        $provider->consult('teste-local');
    }

    public static function legacySignedSales(): array
    {
        return [
            'perfil regular gravado em 2026' => ['2026', '<IBSCBS><CST>000</CST><cClassTrib>000001</cClassTrib></IBSCBS>'],
            'perfil convencional em 2027' => ['2027', ''],
        ];
    }
}
