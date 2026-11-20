<?php

namespace App\Fiscal\Nfse;

use App\Fiscal\FiscalTransportException;
use App\Services\Decimal;

/** Read-only verification of previously archived XML and its frozen commercial data. */
final class DpsVerifier
{
    public const NS = 'http://www.sped.fazenda.gov.br/nfse';

    private const DS = 'http://www.w3.org/2000/09/xmldsig#';

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
                $fields = [];
                $this->readFields($info, '', $fields);
                if (($expected['dps_id'] ?? '') !== $id || $fields !== $this->snapshotFields($expected)) {
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

    /** Compare the signed leaf values and their order without constructing an XML document. */
    private function readFields(\DOMElement $element, string $path, array &$fields): void
    {
        if ($element->namespaceURI !== self::NS || ($path === ''
            ? $element->attributes->length !== 1 || ! $element->hasAttribute('Id')
            : $element->attributes->length !== 0)) {
            throw new FiscalTransportException('nfse_frozen_xml_context_mismatch');
        }
        $text = '';
        $hasChildren = false;
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $hasChildren = true;
                $key = $path === '' ? $child->localName : $path.'/'.$child->localName;
                $this->readFields($child, $key, $fields);
            } elseif ($child instanceof \DOMText) {
                $text .= $child->nodeValue;
            } elseif (! ($child instanceof \DOMComment)) {
                throw new FiscalTransportException('nfse_frozen_xml_context_mismatch');
            }
        }
        if ($hasChildren) {
            if ($text !== '') {
                throw new FiscalTransportException('nfse_frozen_xml_context_mismatch');
            }

            return;
        }
        if (array_key_exists($path, $fields)) {
            throw new FiscalTransportException('nfse_frozen_xml_context_mismatch');
        }
        $fields[$path] = $text;
    }

    private function snapshotFields(array $snapshot): array
    {
        $settings = $snapshot['configuration'];
        $fields = [
            'tpAmb' => '2', 'dhEmi' => $snapshot['issued_at'], 'verAplic' => 'Vendra 1.0',
            'serie' => (string) $snapshot['series'], 'nDPS' => (string) $snapshot['number'],
            'dCompet' => $snapshot['competence'], 'tpEmit' => '1', 'finNFSe' => '0',
            'cLocEmi' => $settings['municipality_code'], 'prest/CNPJ' => $snapshot['issuer']['cnpj'],
        ];
        if (! empty($settings['municipal_registration'])) {
            $fields['prest/IM'] = $settings['municipal_registration'];
        }
        $fields['prest/regTrib/opSimpNac'] = (string) $settings['op_simp_nac'];
        if ($fields['prest/regTrib/opSimpNac'] === '3') {
            $fields['prest/regTrib/regApTribSN'] = (string) $settings['reg_ap_trib_sn'];
        }
        $fields['prest/regTrib/regEspTrib'] = (string) $settings['reg_esp_trib'];
        if (! empty($snapshot['customer'])) {
            $document = $snapshot['customer']['document'];
            $fields[strlen($document) === 14 ? 'toma/CNPJ' : 'toma/CPF'] = $document;
            $fields['toma/xNome'] = $snapshot['customer']['name'];
        }
        $fields['serv/locPrest/cLocPrestacao'] = $settings['municipality_code'];
        $fields['serv/cServ/cTribNac'] = $settings['service_national_code'];
        if (! empty($settings['service_municipal_code'])) {
            $fields['serv/cServ/cTribMun'] = $settings['service_municipal_code'];
        }
        $fields['serv/cServ/cNBS'] = $settings['nbs'];
        $fields['serv/cServ/xDescServ'] = $snapshot['service']['description'];
        $fields['valores/vServPrest/vServ'] = $snapshot['total'];
        $fields['valores/trib/tribMun/tribISSQN'] = '1';
        $fields['valores/trib/tribMun/tpRetISSQN'] = '1';
        if ($settings['total_tax_mode'] === 'simples_percentage') {
            $fields['valores/trib/totTrib/pTotTribSN'] = Decimal::input((string) $settings['total_tax_percent'], 2, 'total_tax_percent', '0', '99.99');
        } else {
            $fields['valores/trib/totTrib/indTotTrib'] = '0';
        }

        return array_map('strval', $fields);
    }
}
