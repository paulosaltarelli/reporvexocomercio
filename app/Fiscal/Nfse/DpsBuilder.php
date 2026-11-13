<?php

namespace App\Fiscal\Nfse;

use App\Fiscal\FiscalTransportException;
use App\Services\Decimal;

/** Local XML preparation only; XSD validity is not tax authorization or API acceptance. */
final class DpsBuilder
{
    public const NS = 'http://www.sped.fazenda.gov.br/nfse';
    private const DS = 'http://www.w3.org/2000/09/xmldsig#';

    public function build(array $snapshot, #[\SensitiveParameter] string $pfx, #[\SensitiveParameter] string $password): string
    {
        $doc = $this->unsigned($snapshot);
        $root = $doc->documentElement;
        $info = $doc->getElementsByTagNameNS(self::NS, 'infDPS')->item(0);
        $this->sign($doc, $root, $info, $pfx, $password);
        $xml = $doc->saveXML();
        $this->validate($xml, $snapshot);
        return $xml;
    }

    private function unsigned(array $snapshot): \DOMDocument
    {
        if (($snapshot['environment'] ?? null) !== 'restricted' || ! preg_match('/^DPS[0-9]{7}2[0-9]{14}[0-9]{20}$/D', $snapshot['dps_id'] ?? '')) {
            throw new FiscalTransportException('nfse_restricted_snapshot_required');
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElementNS(self::NS, 'DPS');
        $root->setAttribute('versao', '1.01');
        $doc->appendChild($root);
        $info = $this->node($doc, $root, 'infDPS');
        $info->setAttribute('Id', $snapshot['dps_id']);
        $p = $snapshot['configuration'];
        foreach (['tpAmb' => '2', 'dhEmi' => $snapshot['issued_at'], 'verAplic' => 'Vendra 1.0', 'serie' => (string) $snapshot['series'], 'nDPS' => (string) $snapshot['number'], 'dCompet' => $snapshot['competence'], 'tpEmit' => '1', 'finNFSe' => '0', 'cLocEmi' => $p['municipality_code']] as $name => $value) {
            $this->node($doc, $info, $name, $value);
        }
        $prest = $this->node($doc, $info, 'prest');
        $this->node($doc, $prest, 'CNPJ', $snapshot['issuer']['cnpj']);
        if (! empty($p['municipal_registration'])) {
            $this->node($doc, $prest, 'IM', $p['municipal_registration']);
        }
        $reg = $this->node($doc, $prest, 'regTrib');
        $this->node($doc, $reg, 'opSimpNac', (string) $p['op_simp_nac']);
        if ((string) $p['op_simp_nac'] === '3') {
            $this->node($doc, $reg, 'regApTribSN', (string) $p['reg_ap_trib_sn']);
        }
        $this->node($doc, $reg, 'regEspTrib', (string) $p['reg_esp_trib']);
        if (! empty($snapshot['customer'])) {
            $customer = $this->node($doc, $info, 'toma');
            $this->node($doc, $customer, strlen($snapshot['customer']['document']) === 14 ? 'CNPJ' : 'CPF', $snapshot['customer']['document']);
            $this->node($doc, $customer, 'xNome', $snapshot['customer']['name']);
        }
        $serv = $this->node($doc, $info, 'serv');
        $loc = $this->node($doc, $serv, 'locPrest');
        $this->node($doc, $loc, 'cLocPrestacao', $p['municipality_code']);
        $code = $this->node($doc, $serv, 'cServ');
        $this->node($doc, $code, 'cTribNac', $p['service_national_code']);
        if (! empty($p['service_municipal_code'])) {
            $this->node($doc, $code, 'cTribMun', $p['service_municipal_code']);
        }
        $this->node($doc, $code, 'cNBS', $p['nbs']);
        $this->node($doc, $code, 'xDescServ', $snapshot['service']['description']);
        $values = $this->node($doc, $info, 'valores');
        $serviceValues = $this->node($doc, $values, 'vServPrest');
        $this->node($doc, $serviceValues, 'vServ', $snapshot['total']);
        $tax = $this->node($doc, $values, 'trib');
        $municipal = $this->node($doc, $tax, 'tribMun');
        $this->node($doc, $municipal, 'tribISSQN', '1');
        $this->node($doc, $municipal, 'tpRetISSQN', '1');
        $totalTax = $this->node($doc, $tax, 'totTrib');
        if ($p['total_tax_mode'] === 'simples_percentage') {
            $this->node($doc, $totalTax, 'pTotTribSN', Decimal::input((string) $p['total_tax_percent'], 2, 'total_tax_percent', '0', '99.99'));
        } else {
            $this->node($doc, $totalTax, 'indTotTrib', '0');
        }
        return $doc;
    }

    public function validate(string $xml, ?array $expected = null): void
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument;
            if (strlen($xml) > 1048576 || stripos($xml, '<!DOCTYPE') !== false || ! $doc->loadXML($xml, LIBXML_NONET) || ! $doc->schemaValidate(__DIR__.'/schemas/DPS_v1.01.xsd')) {
                throw new FiscalTransportException('nfse_dps_schema_invalid');
            }
            $xp = new \DOMXPath($doc);
            $xp->registerNamespace('n', self::NS);
            if ($xp->evaluate('string(/n:DPS/n:infDPS/n:tpAmb)') !== '2') {
                throw new FiscalTransportException('nfse_production_blocked');
            }
            $xp->registerNamespace('ds', self::DS);
            $info = $xp->query('/n:DPS/n:infDPS')->item(0);
            $signedInfo = $xp->query('/n:DPS/ds:Signature/ds:SignedInfo')->item(0);
            $signatureValue = $xp->evaluate('string(/n:DPS/ds:Signature/ds:SignatureValue)');
            $certificateValue = $xp->evaluate('string(/n:DPS/ds:Signature/ds:KeyInfo/ds:X509Data/ds:X509Certificate)');
            $transforms = $signedInfo ? $xp->query('ds:Reference/ds:Transforms/ds:Transform', $signedInfo) : null;
            if (! $info || ! $signedInfo || $xp->query('//ds:Signature')->length !== 1
                || $xp->query('//n:infDPS')->length !== 1
                || $xp->evaluate('string(ds:Reference/@URI)', $signedInfo) !== '#'.$info->getAttribute('Id')
                || $xp->query('ds:Reference', $signedInfo)->length !== 1
                || ! $transforms || $transforms->length !== 2
                || $transforms->item(0)->getAttribute('Algorithm') !== self::DS.'enveloped-signature'
                || $transforms->item(1)->getAttribute('Algorithm') !== 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315'
                || $transforms->item(0)->hasChildNodes() || $transforms->item(1)->hasChildNodes()
                || $xp->evaluate('string(ds:CanonicalizationMethod/@Algorithm)', $signedInfo) !== 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315'
                || $xp->evaluate('string(ds:SignatureMethod/@Algorithm)', $signedInfo) !== self::DS.'rsa-sha1'
                || $xp->evaluate('string(ds:Reference/ds:DigestMethod/@Algorithm)', $signedInfo) !== self::DS.'sha1'
                || ! hash_equals(base64_encode(hash('sha1', $info->C14N(false, false), true)), $xp->evaluate('string(ds:Reference/ds:DigestValue)', $signedInfo))) {
                throw new FiscalTransportException('nfse_signature_invalid');
            }
            $certificate = "-----BEGIN CERTIFICATE-----\n".chunk_split(preg_replace('/\s/', '', $certificateValue), 64, "\n")."-----END CERTIFICATE-----\n";
            if (openssl_verify($signedInfo->C14N(false, false), base64_decode($signatureValue, true) ?: '', $certificate, OPENSSL_ALGO_SHA1) !== 1) {
                throw new FiscalTransportException('nfse_signature_invalid');
            }
            if ($expected !== null) {
                $p = $expected['configuration'] ?? [];
                $cnpj = $expected['issuer']['cnpj'] ?? '';
                $id = 'DPS'.($p['municipality_code'] ?? '').'2'.$cnpj.str_pad((string) ($expected['series'] ?? ''), 5, '0', STR_PAD_LEFT).str_pad((string) ($expected['number'] ?? ''), 15, '0', STR_PAD_LEFT);
                $unsigned = $this->unsigned($expected);
                $expectedInfo = $unsigned->getElementsByTagNameNS(self::NS, 'infDPS')->item(0);
                if (! hash_equals($expectedInfo->C14N(false, false), $info->C14N(false, false))) {
                    throw new FiscalTransportException('nfse_frozen_xml_context_mismatch');
                }
                if (($expected['environment'] ?? '') !== 'restricted' || $id !== $info->getAttribute('Id')
                    || (string) ($p['series'] ?? '') !== (string) ($expected['series'] ?? '')
                    || ! hash_equals((string) ($expected['certificate_fingerprint'] ?? ''), (string) openssl_x509_fingerprint($certificate, 'sha256'))) {
                    throw new FiscalTransportException('nfse_frozen_certificate_or_identifier_mismatch');
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function node(\DOMDocument $doc, \DOMElement $parent, string $name, ?string $value = null): \DOMElement
    {
        $element = $doc->createElementNS(self::NS, $name);
        if ($value !== null) {
            $element->appendChild($doc->createTextNode($value));
        }
        $parent->appendChild($element);
        return $element;
    }

    private function sign(\DOMDocument $doc, \DOMElement $root, \DOMElement $info, #[\SensitiveParameter] string $pfx, #[\SensitiveParameter] string $password): void
    {
        $keys = [];
        if (! @openssl_pkcs12_read($pfx, $keys, $password) || ! @openssl_x509_check_private_key($keys['cert'] ?? '', $keys['pkey'] ?? '')) {
            throw new FiscalTransportException('nfse_certificate_invalid');
        }
        $sig = $doc->createElementNS(self::DS, 'Signature');
        $root->appendChild($sig);
        $add = function (\DOMElement $parent, string $name, ?string $value = null) use ($doc) {
            $el = $doc->createElementNS(self::DS, $name);
            if ($value !== null) {
                $el->appendChild($doc->createTextNode($value));
            }
            $parent->appendChild($el);
            return $el;
        };
        $signedInfo = $add($sig, 'SignedInfo');
        $add($signedInfo, 'CanonicalizationMethod')->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $add($signedInfo, 'SignatureMethod')->setAttribute('Algorithm', self::DS.'rsa-sha1');
        $ref = $add($signedInfo, 'Reference');
        $ref->setAttribute('URI', '#'.$info->getAttribute('Id'));
        $transforms = $add($ref, 'Transforms');
        $add($transforms, 'Transform')->setAttribute('Algorithm', self::DS.'enveloped-signature');
        $add($transforms, 'Transform')->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $add($ref, 'DigestMethod')->setAttribute('Algorithm', self::DS.'sha1');
        $add($ref, 'DigestValue', base64_encode(hash('sha1', $info->C14N(false, false), true)));
        if (! openssl_sign($signedInfo->C14N(false, false), $signature, $keys['pkey'], OPENSSL_ALGO_SHA1) || openssl_verify($signedInfo->C14N(false, false), $signature, $keys['cert'], OPENSSL_ALGO_SHA1) !== 1) {
            throw new FiscalTransportException('nfse_signature_invalid');
        }
        $add($sig, 'SignatureValue', base64_encode($signature));
        $keyInfo = $add($sig, 'KeyInfo');
        $data = $add($keyInfo, 'X509Data');
        $certificate = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s/', '', $keys['cert']);
        $add($data, 'X509Certificate', $certificate);
    }
}
