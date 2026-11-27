<?php

namespace Tests\Unit;

use DOMXPath;
use NFePHP\Common\Exception\SignerException;
use NFePHP\Common\Signer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\SimplesResaleXmlFixture;

final class SimplesResaleXmlTest extends TestCase
{
    public function test_signed_model_65_validates_against_the_official_schema_and_keeps_the_simples_groups(): void
    {
        $xml = SimplesResaleXmlFixture::build();
        self::assertTrue($xml->schemaValidate(SimplesResaleXmlFixture::SCHEMA));
        self::assertTrue(Signer::isSigned($xml->saveXML(), 'infNFe'));
        self::assertSame([], SimplesResaleXmlFixture::totalViolations($xml));

        $path = new DOMXPath($xml);
        $path->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $note = $path->query('/n:NFe/n:infNFe')->item(0);
        foreach ([
            'n:ide/n:cUF' => '31', 'n:ide/n:mod' => '65', 'n:ide/n:tpAmb' => '2', 'n:ide/n:tpNF' => '1',
            'n:ide/n:dhEmi' => '2027-03-01T10:00:00-03:00', 'n:ide/n:idDest' => '1', 'n:ide/n:finNFe' => '1',
            'n:ide/n:indFinal' => '1', 'n:ide/n:indPres' => '1', 'n:emit/n:CRT' => '1',
            'n:emit/n:CNPJ' => str_repeat('0', 14), 'n:total/n:ICMSTot/n:vDesc' => '0.10',
            'n:total/n:ICMSTot/n:vProd' => '162.90', 'n:total/n:ICMSTot/n:vNF' => '162.80',
            'n:total/n:IBSCBSTot/n:vBCIBSCBS' => '0.00', 'n:total/n:IBSCBSTot/n:gTribSN/n:vIBSSN' => '0.02',
            'n:total/n:IBSCBSTot/n:gTribSN/n:vCBSSN' => '1.00', 'n:total/n:vNFTot' => '162.80',
            'n:pag/n:detPag/n:vPag' => '162.80',
        ] as $query => $expected) {
            self::assertSame($expected, $path->evaluate("string($query)", $note), $query);
        }
        self::assertSame(0, $path->query('//n:tpRegimeIBSCBS | //n:gIBSCBS | //n:gIBS | //n:gCBS | //n:protNFe | //n:dest')->length);
        self::assertSame('ensaio.invalid', parse_url($path->evaluate('string(//n:qrCode)'), PHP_URL_HOST));
        $items = $path->query('n:det', $note);
        self::assertSame(2, $items->length);
        $expectedItems = [['79.90', '0.49'], ['82.90', '0.51']];
        foreach ($items as $index => $item) {
            [$net, $cbs] = $expectedItems[$index];
            foreach ([
                'n:prod/n:CFOP' => '5102', 'n:prod/n:cAtivSN' => '01', 'n:imposto/n:ICMS/n:ICMSSN102/n:CSOSN' => '102',
                'n:imposto/n:IBSCBS/n:CST' => '000', 'n:imposto/n:IBSCBS/n:cClassTrib' => '000001',
                'n:imposto/n:IBSCBS/n:gTribSN/n:gIBSSN/n:pIBSSN' => '0.0068',
                'n:imposto/n:IBSCBS/n:gTribSN/n:gIBSSN/n:vIBSSN' => '0.01',
                'n:imposto/n:IBSCBS/n:gTribSN/n:gCBSSN/n:pCBSSN' => '0.6132',
                'n:imposto/n:IBSCBS/n:gTribSN/n:gCBSSN/n:vCBSSN' => $cbs, 'n:vItem' => $net,
            ] as $query => $expected) {
                self::assertSame($expected, $path->evaluate("string($query)", $item), $query);
            }
        }
    }

    public static function mismatchedTotals(): array
    {
        return [
            'item com grupo e total sem grupo' => ['//n:IBSCBSTot/n:gTribSN', null, 'W59h-10'],
            'total com grupo e itens sem grupo' => ['//n:det/n:imposto/n:IBSCBS/n:gTribSN', null, 'W59h-20'],
            'IBS calculado no agregado perde um centavo' => ['//n:IBSCBSTot/n:gTribSN/n:vIBSSN', '0.01', 'W59i-10'],
            'CBS diverge um centavo' => ['//n:IBSCBSTot/n:gTribSN/n:vCBSSN', '0.99', 'W59j-10'],
        ];
    }

    #[DataProvider('mismatchedTotals')]
    public function test_detects_the_official_total_rule_even_when_xsd_accepts_the_content(string $query, ?string $value, string $rule): void
    {
        $xml = SimplesResaleXmlFixture::build();
        $path = new DOMXPath($xml);
        $path->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        foreach ($path->query($query) as $node) {
            if ($value === null) {
                $node->parentNode->removeChild($node);
            } else {
                $node->textContent = $value;
            }
        }
        self::assertTrue($xml->schemaValidate(SimplesResaleXmlFixture::SCHEMA));
        self::assertSame([$rule], SimplesResaleXmlFixture::totalViolations($xml));
    }

    public function test_official_xsd_rejects_an_unknown_activity(): void
    {
        $xml = SimplesResaleXmlFixture::build();
        $xml->getElementsByTagName('cAtivSN')->item(0)->textContent = '99';
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertFalse($xml->schemaValidate(SimplesResaleXmlFixture::SCHEMA));
            self::assertStringContainsString('cAtivSN', libxml_get_last_error()->message);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function test_changing_a_simples_value_invalidates_the_signature(): void
    {
        $xml = SimplesResaleXmlFixture::build();
        $xml->getElementsByTagName('vIBSSN')->item(0)->textContent = '0.02';
        $this->expectException(SignerException::class);
        Signer::isSigned($xml->saveXML(), 'infNFe');
    }
}
