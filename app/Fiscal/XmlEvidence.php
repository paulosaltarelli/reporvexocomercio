<?php

namespace App\Fiscal;

use App\Models\FiscalCredential;
use NFePHP\Common\Signer;

final class XmlEvidence
{
    public function authorization(#[\SensitiveParameter] string $xml, #[\SensitiveParameter] array $payload, #[\SensitiveParameter] FiscalCredential $credential): array
    {
        try {
            if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
                throw new \RuntimeException;
            }
            app(FiscalSchema::class)->validate($xml, $payload['_schema_package'] ?? FiscalSchema::PACKAGE);
            $dom = new \DOMDocument;
            $dom->preserveWhiteSpace = false;
            if (! @$dom->loadXML($xml, LIBXML_NONET)) {
                throw new \RuntimeException;
            }
            $xp = new \DOMXPath($dom);
            $xp->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
            $xp->registerNamespace('s', 'http://www.w3.org/2000/09/xmldsig#');
            if ($dom->documentElement->localName !== 'nfeProc' || $xp->query('//n:infNFe')->length !== 1 || $xp->query('//s:Signature')->length !== 1 || $xp->query('//n:infProt')->length !== 1) {
                throw new \RuntimeException;
            }
            $value = fn (string $q) => $xp->evaluate('string('.$q.')');
            $base = '/n:nfeProc/n:NFe/n:infNFe';
            $prot = '/n:nfeProc/n:protNFe/n:infProt';
            $key = $value($prot.'/n:chNFe');
            if ($value($prot.'/n:cStat') !== '100' || $value($prot.'/n:tpAmb') !== '2' || $value($base.'/n:ide/n:tpAmb') !== '2' || $value($base.'/n:ide/n:mod') !== '65' || $value($base.'/n:ide/n:tpEmis') !== '1'
                || $value($base.'/n:emit/n:CNPJ') !== $payload['cnpj_emitente'] || (int) $value($base.'/n:ide/n:nNF') !== (int) $payload['numero'] || (int) $value($base.'/n:ide/n:serie') !== (int) $payload['serie']
                || $value($base.'/@Id') !== 'NFe'.$key || $value('//s:Reference/@URI') !== '#NFe'.$key || ! preg_match('/^[0-9A-Z]{44}$/D', $key)
                || bccomp($value($base.'/n:total/n:ICMSTot/n:vNF'), $payload['valor_total'], 2) !== 0 || ! $value($prot.'/n:nProt') || $value($prot.'/n:digVal') !== $value('//s:DigestValue')) {
                throw new \RuntimeException;
            }
            // Compare the entire signed fiscal content with the immutable request,
            // rather than accepting a different invoice with an equal grand total.
            $submittedXml = $payload['_signed_xml'] ?? null;
            if (! is_string($submittedXml) || $submittedXml === '' || stripos($submittedXml, '<!DOCTYPE') !== false || stripos($submittedXml, '<!ENTITY') !== false) {
                throw new \RuntimeException;
            }
            $submitted = new \DOMDocument;
            $submitted->preserveWhiteSpace = false;
            if (! @$submitted->loadXML($submittedXml, LIBXML_NONET)) {
                throw new \RuntimeException;
            }
            $submittedXpath = new \DOMXPath($submitted);
            $submittedXpath->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
            $originalNodes = $submittedXpath->query('//n:infNFe');
            $returnedNodes = $xp->query('/n:nfeProc/n:NFe/n:infNFe');
            if ($originalNodes->length !== 1 || $returnedNodes->length !== 1) {
                throw new \RuntimeException;
            }
            $originalContent = $originalNodes->item(0)->C14N(true, false);
            $returnedContent = $returnedNodes->item(0)->C14N(true, false);
            if (! is_string($originalContent) || ! is_string($returnedContent) || ! hash_equals($originalContent, $returnedContent)) {
                throw new \RuntimeException;
            }
            $cert = '-----BEGIN CERTIFICATE-----'.PHP_EOL.chunk_split($value('//s:X509Certificate'), 64, PHP_EOL).'-----END CERTIFICATE-----';
            if (! hash_equals($payload['_certificate_fingerprint'] ?? $credential->certificate_fingerprint, (string) openssl_x509_fingerprint($cert, 'sha256')) || ! Signer::isSigned($xml, 'infNFe')) {
                throw new \RuntimeException;
            }

            return ['key' => $key, 'protocol' => $value($prot.'/n:nProt'), 'authorized_at' => $value($prot.'/n:dhRecbto')];
        } catch (\Throwable) {
            throw new FiscalTransportException('authorization_xml_invalid');
        }
    }
    /** Verify the signed cancellation request and its correlated SEFAZ response. */
    public function cancellation(#[\SensitiveParameter] string $xml, #[\SensitiveParameter] array $expected, #[\SensitiveParameter] FiscalCredential $credential): array
    {
        try {
            $dom = $this->parse($xml);
            $xp = new \DOMXPath($dom);
            $xp->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
            $xp->registerNamespace('s', 'http://www.w3.org/2000/09/xmldsig#');
            if ($dom->documentElement->localName !== 'procEventoNFe' ||
                $xp->query('/n:procEventoNFe/n:evento/n:infEvento')->length !== 1 ||
                $xp->query('/n:procEventoNFe/n:retEvento/n:infEvento')->length !== 1 ||
                $xp->query('//s:Signature')->length !== 1 || $xp->query('//s:Reference')->length !== 1) {
                throw new \RuntimeException;
            }
            $request = '/n:procEventoNFe/n:evento/n:infEvento';
            $response = '/n:procEventoNFe/n:retEvento/n:infEvento';
            $value = fn (string $path) => $xp->evaluate('string('.$path.')');
            $key = (string) ($expected['_access_key'] ?? '');
            $sequence = (int) ($expected['_event_sequence'] ?? 1);
            $eventId = 'ID110111'.$key.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
            if (! preg_match('/^[0-9]{44}$/D', $key) || $sequence < 1 || $sequence > 99 ||
                $value($request.'/@Id') !== $eventId || $value('//s:Reference/@URI') !== '#'.$eventId ||
                $value($request.'/n:CNPJ') !== ($expected['cnpj_emitente'] ?? '') ||
                $value($request.'/n:cOrgao') !== substr($key, 0, 2) ||
                ! preg_match('/^[0-9]{15}$/D', (string) ($expected['_protocol'] ?? '')) ||
                $value($request.'/n:detEvento/n:nProt') !== ($expected['_protocol'] ?? '') ||
                $value($request.'/n:detEvento/n:descEvento') !== 'Cancelamento' ||
                mb_strlen($value($request.'/n:detEvento/n:xJust')) < 15 || mb_strlen($value($request.'/n:detEvento/n:xJust')) > 255 ||
                $value($response.'/n:cOrgao') !== substr($key, 0, 2) || $value($response.'/n:dhRegEvento') === '' ||
                ! in_array($value($response.'/n:cStat'), ['135', '155'], true) ||
                ! preg_match('/^[0-9]{15}$/D', $value($response.'/n:nProt'))) {
                throw new \RuntimeException;
            }
            foreach ([$request, $response] as $base) {
                if ($value($base.'/n:chNFe') !== $key || $value($base.'/n:tpAmb') !== '2' ||
                    $value($base.'/n:tpEvento') !== '110111' || (int) $value($base.'/n:nSeqEvento') !== $sequence) {
                    throw new \RuntimeException;
                }
            }
            $original = $this->parse((string) ($expected['_signed_event_xml'] ?? ''));
            $originalXpath = new \DOMXPath($original);
            $originalXpath->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
            $originalNodes = $originalXpath->query('//n:evento/n:infEvento');
            $returnedNode = $xp->query($request)->item(0);
            if ($originalNodes->length !== 1 || ! hash_equals((string) $originalNodes->item(0)->C14N(true, false), (string) $returnedNode->C14N(true, false))) {
                throw new \RuntimeException;
            }
            $cert = '-----BEGIN CERTIFICATE-----'.PHP_EOL.chunk_split($value('//s:X509Certificate'), 64, PHP_EOL).'-----END CERTIFICATE-----';
            $eventFingerprint = (string) openssl_x509_fingerprint($cert, 'sha256');
            $matchesCurrent = hash_equals((string) $credential->certificate_fingerprint, $eventFingerprint);
            $matchesOriginal = isset($expected['_certificate_fingerprint']) && hash_equals((string) $expected['_certificate_fingerprint'], $eventFingerprint);
            if ((! $matchesCurrent && ! $matchesOriginal) || ! Signer::isSigned($xml, 'infEvento')) {
                throw new \RuntimeException;
            }
            return ['key' => $key, 'protocol' => $value($response.'/n:nProt'), 'code' => $value($response.'/n:cStat')];
        } catch (\Throwable) {
            throw new FiscalTransportException('cancellation_xml_invalid');
        }
    }

    private function parse(#[\SensitiveParameter] string $xml): \DOMDocument
    {
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new \RuntimeException;
        }
        $dom = new \DOMDocument;
        $dom->preserveWhiteSpace = false;
        if (! @$dom->loadXML($xml, LIBXML_NONET)) {
            throw new \RuntimeException;
        }
        return $dom;
    }

}
