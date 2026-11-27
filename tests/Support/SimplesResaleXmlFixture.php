<?php

namespace Tests\Support;

use App\Fiscal\FiscalSchema;
use App\Fiscal\NfcePayload;
use App\Services\Decimal;
use DOMDocument;
use DOMElement;
use DOMXPath;
use NFePHP\Common\Certificate;
use NFePHP\Common\Certificate\CertificationChain;
use NFePHP\Common\Certificate\PrivateKey;
use NFePHP\Common\Certificate\PublicKey;
use NFePHP\Common\Signer;
use NFePHP\NFe\Factories\QRCode;
use NFePHP\NFe\Make;
use RuntimeException;

/** Fixed model-65 test case. All inputs are synthetic, including issuer and signing key. */
final class SimplesResaleXmlFixture
{
    public const SCHEMA = FiscalSchema::NFCE_SCHEMA_DIRECTORY.'/nfe_v4.00.xsd';
    private const NS = 'http://www.portalfiscal.inf.br/nfe';

    public static function build(): DOMDocument
    {
        $make = new Make(FiscalSchema::PACKAGE);
        $make->setCalculationMethod(Make::METHOD_CALCULATION_V2);
        $make->taginfNFe((object) ['versao' => '4.00']);
        $make->tagide((object) [
            'cUF' => '31', 'cNF' => '71836425', 'natOp' => 'REVENDA INTERNA DE MERCADORIA',
            'mod' => 65, 'serie' => 1, 'nNF' => 1, 'dhEmi' => '2027-03-01T10:00:00-03:00',
            'tpNF' => 1, 'idDest' => 1, 'cMunFG' => '3103504', 'tpImp' => 4, 'tpEmis' => 1,
            'tpAmb' => 2, 'finNFe' => 1, 'indFinal' => 1, 'indPres' => 1,
            'procEmi' => 0, 'verProc' => 'ENSAIO LOCAL',
        ]);
        $make->tagemit((object) [
            'CNPJ' => str_repeat('0', 14), 'xNome' => 'LOJA FICTICIA DO ENSAIO', 'IE' => str_repeat('0', 13), 'CRT' => 1,
        ]);
        $make->tagenderEmit((object) [
            'xLgr' => 'RUA DO ENSAIO', 'nro' => '1', 'xBairro' => 'BAIRRO FICTICIO',
            'cMun' => '3103504', 'xMun' => 'ARAGUARI', 'UF' => 'MG', 'CEP' => '38440000',
        ]);
        $rows = [
            ['2.000', '40.00', '80.00', '0.10', '79.90'],
            ['1.000', '82.90', '82.90', '0.00', '82.90'],
        ];
        $amounts = new NfcePayload;
        $netTotal = '0.00';
        foreach ($rows as $index => $row) {
            $line = $amounts->fiscalAmounts(...$row);
            $item = $index + 1;
            $make->tagprod((object) [
                'item' => $item, 'cProd' => 'ENSAIO-'.$item, 'cEAN' => 'SEM GTIN',
                'xProd' => $item === 1 ? 'NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL' : 'TOALHA DE ALGODAO FICTICIA',
                'NCM' => '63026000', 'CFOP' => '5102', 'uCom' => 'UN', 'qCom' => $line['quantity'],
                'vUnCom' => $line['unit_price'], 'vProd' => $line['gross'], 'cEANTrib' => 'SEM GTIN',
                'uTrib' => 'UN', 'qTrib' => $line['quantity'], 'vUnTrib' => $line['unit_price'],
                'vDesc' => $line['discount'] === '0.00' ? null : $line['discount'], 'indTot' => 1,
            ]);
            $make->tagimposto((object) ['item' => $item]);
            $make->tagICMSSN((object) ['item' => $item, 'orig' => 0, 'CSOSN' => '102']);
            $netTotal = bcadd($netTotal, $line['net'], 2);
        }
        $make->tagICMSTot((object) ['vNF' => $netTotal]);
        $make->tagtransp((object) ['modFrete' => 9]);
        $make->tagpag((object) []);
        $make->tagdetPag((object) ['indPag' => 0, 'tPag' => '01', 'vPag' => $netTotal]);
        $xml = new DOMDocument;
        $base = $make->getXML();
        if ($make->getErrors() || ! $xml->loadXML($base, LIBXML_NONET)) {
            throw new RuntimeException('Falhou a montagem dos itens de ensaio. '.implode(' ', $make->getErrors()));
        }

        // The pinned Make does not expose cAtivSN or gTribSN. Only this fixed case adds them before signing.
        $ibsRate = bcdiv(bcmul('4.00', '0.17', 4), '100', 4);
        $cbsRate = bcdiv(bcmul('4.00', '15.33', 4), '100', 4);
        $ibsTotal = $cbsTotal = '0.00';
        foreach ($xml->getElementsByTagNameNS(self::NS, 'det') as $detail) {
            $product = $detail->getElementsByTagNameNS(self::NS, 'prod')->item(0);
            self::add($product, 'cAtivSN', '01');
            $gross = $product->getElementsByTagNameNS(self::NS, 'vProd')->item(0)->textContent;
            $discount = $product->getElementsByTagNameNS(self::NS, 'vDesc')->item(0)?->textContent ?? '0.00';
            $net = bcsub($gross, $discount, 2);
            $tax = self::add($detail->getElementsByTagNameNS(self::NS, 'imposto')->item(0), 'IBSCBS');
            self::add($tax, 'CST', '000');
            self::add($tax, 'cClassTrib', '000001');
            $simples = self::add($tax, 'gTribSN');
            $ibsTotal = bcadd($ibsTotal, self::itemTax($simples, 'IBS', $ibsRate, $net), 2);
            $cbsTotal = bcadd($cbsTotal, self::itemTax($simples, 'CBS', $cbsRate, $net), 2);
            self::add($detail, 'vItem', $net);
        }
        $total = $xml->getElementsByTagNameNS(self::NS, 'total')->item(0);
        $rtc = self::add($total, 'IBSCBSTot');
        self::add($rtc, 'vBCIBSCBS', '0.00'); // W35 is the sum of gIBSCBS/vBC only.
        $simplesTotal = self::add($rtc, 'gTribSN');
        self::add($simplesTotal, 'vIBSSN', $ibsTotal);
        self::add($simplesTotal, 'vCBSSN', $cbsTotal);
        self::add($total, 'vNFTot', $netTotal);

        $certificate = self::ephemeralCertificate();
        $signed = Signer::sign($certificate, $xml->saveXML(), 'infNFe');
        $xml->loadXML($signed, LIBXML_NONET);
        $withQr = QRCode::putQRTag($xml, '', '', '300', 'https://ensaio.invalid/qr', 'https://ensaio.invalid/consulta', $certificate);
        $xml->loadXML($withQr, LIBXML_NONET);
        return $xml;
    }

    /** NT 2026.005 §4. Return the rule IDs so each diagnostic can be tested independently of XSD and signature. */
    public static function totalViolations(DOMDocument $xml): array
    {
        $path = new DOMXPath($xml);
        $path->registerNamespace('n', self::NS);
        $items = $path->query('/n:NFe/n:infNFe/n:det/n:imposto/n:IBSCBS/n:gTribSN');
        $total = $path->query('/n:NFe/n:infNFe/n:total/n:IBSCBSTot/n:gTribSN')->item(0);
        if (! $total) {
            return $items->length ? ['W59h-10'] : [];
        }
        if (! $items->length) {
            return ['W59h-20'];
        }
        $violations = [];
        foreach (['IBS' => 'W59i-10', 'CBS' => 'W59j-10'] as $tax => $rule) {
            $sum = '0.00';
            foreach ($items as $item) {
                $value = $path->query("n:g{$tax}SN/n:v{$tax}SN", $item)->item(0);
                if ($value) {
                    $sum = bcadd($sum, $value->textContent, 2);
                }
            }
            if (bccomp($sum, $path->evaluate("string(n:v{$tax}SN)", $total), 2) !== 0) {
                $violations[] = $rule;
            }
        }
        return $violations;
    }

    private static function itemTax(DOMElement $parent, string $tax, string $rate, string $net): string
    {
        $value = Decimal::round(bcdiv(bcmul($net, $rate, 6), '100', 8));
        $group = self::add($parent, 'g'.$tax.'SN');
        self::add($group, 'p'.$tax.'SN', $rate);
        self::add($group, 'v'.$tax.'SN', $value);
        return $value;
    }

    private static function add(DOMElement $parent, string $name, string $value = ''): DOMElement
    {
        return $parent->appendChild($parent->ownerDocument->createElementNS(self::NS, $name, $value));
    }

    private static function ephemeralCertificate(): Certificate
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $request = openssl_csr_new(['commonName' => 'ENSAIO LOCAL SEM VALOR FISCAL'], $key);
        $certificate = openssl_csr_sign($request, null, $key, 1);
        if (! openssl_pkey_export($key, $privatePem) || ! openssl_x509_export($certificate, $publicPem)) {
            throw new RuntimeException('Falhou a criação do certificado efêmero de teste.');
        }
        return new Certificate(new PrivateKey($privatePem), new PublicKey($publicPem), new CertificationChain);
    }
}
