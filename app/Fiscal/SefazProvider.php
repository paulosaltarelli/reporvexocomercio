<?php

namespace App\Fiscal;

use App\Models\FiscalCredential;
use App\Models\FiscalIssuer;
use NFePHP\Common\Signer;
use NFePHP\DA\NFe\Danfce;
use NFePHP\NFe\Complements;

final class SefazProvider implements FiscalProvider
{
    public function __construct(private FiscalIssuer $issuer, #[\SensitiveParameter] private FiscalCredential $credential, #[\SensitiveParameter] private array $payload = [], private ?SefazTransport $transport = null)
    {
        if ($issuer->environment !== 'homologation' || $issuer->provider !== 'sefaz_direct' || (int) $credential->issuer_id !== (int) $issuer->id) {
            throw new FiscalTransportException('provider_context_invalid');
        }
    }

    private function transport(): SefazTransport
    {
        return $this->transport ??= app()->makeWith(SefazTransport::class, [
            'issuer' => $this->payload['_issuer_snapshot'] ?? [],
            'configuration' => $this->payload['_configuration'] ?? [],
            'credential' => $this->credential,
        ]);
    }

    public function issue(string $reference, #[\SensitiveParameter] array $frozenPayload): ProviderResult
    {
        // Never reconstruct or sign again after transmission has become possible.
        $this->payload = $frozenPayload + $this->payload;
        $this->frozen();
        $result = $this->transport()->authorize($this->payload['_signed_xml'], $this->payload['_access_key']);
        return $this->normalize($result['response']);
    }

    public function consult(string $reference): ProviderResult
    {
        $this->frozen();
        $result = $this->transport()->consult($this->payload['_access_key']);
        return $this->normalize($result['response'], true);
    }

    private function frozen(): void
    {
        $xml = (string) ($this->payload['_signed_xml'] ?? '');
        $key = (string) ($this->payload['_access_key'] ?? '');
        $dom = $this->parse($xml);
        $xp = $this->xpath($dom);
        $value = fn (string $q) => $xp->evaluate('string('.$q.')');
        $base = '/n:NFe/n:infNFe';
        if (! preg_match('/^[0-9]{44}$/D', $key) || $dom->documentElement->localName !== 'NFe' || $xp->query('//n:infNFe')->length !== 1 || $value($base.'/@Id') !== 'NFe'.$key
            || $value($base.'/n:emit/n:CNPJ') !== $this->issuer->document || ($this->payload['cnpj_emitente'] ?? '') !== $this->issuer->document
            || $value($base.'/n:ide/n:tpAmb') !== '2' || $value($base.'/n:ide/n:mod') !== '65' || $value($base.'/n:ide/n:tpEmis') !== '1'
            || (int) $value($base.'/n:ide/n:nNF') !== (int) ($this->payload['numero'] ?? 0) || (int) $value($base.'/n:ide/n:serie') !== (int) ($this->payload['serie'] ?? -1)) {
            throw new FiscalTransportException('frozen_xml_context_invalid');
        }
        try {
            $cert = '-----BEGIN CERTIFICATE-----'.PHP_EOL.chunk_split($value('//s:X509Certificate'), 64, PHP_EOL).'-----END CERTIFICATE-----';
            if ($xp->query('//s:Signature')->length !== 1 || ! hash_equals((string) ($this->payload['_certificate_fingerprint'] ?? $this->credential->certificate_fingerprint), (string) openssl_x509_fingerprint($cert, 'sha256')) || ! Signer::isSigned($xml, 'infNFe')) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            throw new FiscalTransportException('frozen_xml_signature_invalid');
        }
    }

    private function normalize(#[\SensitiveParameter] string $response, bool $consult = false): ProviderResult
    {
        $dom = $this->parse($response);
        $xp = $this->xpath($dom);
        $root = $xp->query('//n:retConsSitNFe | //n:retEnviNFe | //n:retConsReciNFe');
        if ($root->length !== 1) {
            throw new FiscalTransportException('sefaz_response_invalid');
        }
        $node = $root->item(0);
        $get = fn (string $name) => $xp->evaluate('string(n:'.$name.')', $node);
        $code = $get('cStat');
        if ($get('tpAmb') !== '2' || ($consult && $get('chNFe') !== $this->payload['_access_key'])) {
            throw new FiscalTransportException('sefaz_response_context_invalid');
        }
        if ($consult && $code === '217') {
            throw new FiscalTransportException('reference_not_found');
        }
        if ($consult && in_array($code, ['101', '151'], true)) {
            $events = $xp->query('//n:procEventoNFe');
            foreach ($events as $event) {
                if ($xp->evaluate('string(n:evento/n:infEvento/n:tpEvento)', $event) !== '110111') {
                    continue;
                }
                $xml = $dom->saveXML($event);
                $expected = $this->payload + ['_event_sequence' => 1];
                // A query supplies the actual signed request as evidence, not a fabricated event.
                $expected['_signed_event_xml'] = $xml;
                $evidence = app(XmlEvidence::class)->cancellation($xml, $expected, $this->credential);
                return new ProviderResult(DocumentState::Cancelled, $evidence['code'], $evidence['key'], $evidence['protocol'], $xml);
            }
            throw new FiscalTransportException('cancellation_event_missing');
        }
        // An authorization is only the official protocol for this exact signed invoice.
        $protocols = $xp->query('//n:protNFe/n:infProt');
        if ($protocols->length > 1) {
            throw new FiscalTransportException('sefaz_response_ambiguous');
        }
        if ($protocols->length === 1) {
            $prot = $protocols->item(0);
            $get = fn (string $name) => $xp->evaluate('string(n:'.$name.')', $prot);
            if ($get('chNFe') !== $this->payload['_access_key'] || $get('tpAmb') !== '2') {
                throw new FiscalTransportException('sefaz_protocol_context_invalid');
            }
            if ($get('cStat') === '100') {
                try {
                    $xml = Complements::toAuthorize($this->payload['_signed_xml'], $response);
                    $evidence = app(XmlEvidence::class)->authorization($xml, $this->payload, $this->credential);
                    return new ProviderResult(DocumentState::Authorized, '100', $evidence['key'], $evidence['protocol'], $xml, $evidence['authorized_at']);
                } catch (\Throwable) {
                    throw new FiscalTransportException('authorization_xml_invalid');
                }
            }
            $code = $get('cStat');
            if (preg_match('/^[0-9]{3}$/D', $code) && ! in_array($code, ['100', '103', '104', '105', '106', '108', '109', '110', '204', '217', '539', '999'], true)) {
                return new ProviderResult(DocumentState::Rejected, $code);
            }
            return $this->rejectionOrUncertain($code);
        }
        return $this->rejectionOrUncertain($code);
    }

    private function rejectionOrUncertain(string $code): ProviderResult
    {
        // Processing, duplicates, unavailable services and incomplete batches need reconciliation,
        // never a new number or an automatic replay. Only an invoice-level negative protocol is final.
        return new ProviderResult(DocumentState::Uncertain, $code ?: null);
    }

    public function cancel(string $reference, string $reason, string $operationId): ProviderResult
    {
        $this->frozen();
        $result = $this->transport()->cancel($this->payload['_access_key'], (string) ($this->payload['_protocol'] ?? ''), $reason);
        try {
            $requestDom = $this->parse($result['request']);
            $events = $this->xpath($requestDom)->query('//n:evento');
            if ($events->length !== 1) {
                throw new \RuntimeException;
            }
            $request = $requestDom->saveXML($events->item(0));
            $responseDom = $this->parse($result['response']);
            $this->assertCancellationNotRejected($requestDom, $responseDom);
            $envelopes = $this->xpath($requestDom)->query('//n:envEvento');
            if ($envelopes->length !== 1) {
                throw new \RuntimeException;
            }
            $xml = Complements::toAuthorize($requestDom->saveXML($envelopes->item(0)), $result['response']);
            $expected = $this->payload + ['_event_sequence' => 1];
            $expected['_signed_event_xml'] = $request;
            $evidence = app(XmlEvidence::class)->cancellation($xml, $expected, $this->credential);
            return new ProviderResult(DocumentState::Cancelled, $evidence['code'], $evidence['key'], $evidence['protocol'], $xml);
        } catch (FiscalTransportException $e) {
            if (preg_match('/^cancellation_rejected_[0-9]{3}$/D', $e->getMessage())) {
                throw $e;
            }
            throw new FiscalTransportException('cancellation_xml_invalid');
        } catch (\Throwable) {
            throw new FiscalTransportException('cancellation_xml_invalid');
        }
    }

    /** Classify only an invoice-event rejection, never a transport/batch/duplicate outcome. */
    private function assertCancellationNotRejected(\DOMDocument $request, \DOMDocument $response): void
    {
        $req = $this->xpath($request);
        $ret = $this->xpath($response);
        $requestBase = '//n:envEvento/n:evento/n:infEvento';
        $responseBase = '//n:retEnvEvento/n:retEvento/n:infEvento';
        if ($req->query($requestBase)->length !== 1 || $ret->query($responseBase)->length !== 1 || $ret->query('//n:retEnvEvento')->length !== 1) {
            return;
        }
        $q = fn (string $path) => $req->evaluate('string('.$path.')');
        $r = fn (string $path) => $ret->evaluate('string('.$path.')');
        $code = $r($responseBase.'/n:cStat');
        if (! preg_match('/^[2-9][0-9]{2}$/D', $code) || in_array($code, ['204', '539', '573', '999'], true)) {
            return;
        }
        $key = $this->payload['_access_key'];
        $id = 'ID110111'.$key.'01';
        if ($r('//n:retEnvEvento/n:cStat') !== '128' || $q('//n:envEvento/n:idLote') === '' || $q('//n:envEvento/n:idLote') !== $r('//n:retEnvEvento/n:idLote')
            || $q($requestBase.'/@Id') !== $id || $q('//s:Reference/@URI') !== '#'.$id
            || $q($requestBase.'/n:CNPJ') !== $this->issuer->document || $q($requestBase.'/n:detEvento/n:nProt') !== ($this->payload['_protocol'] ?? '')
            || $req->query('//s:Signature')->length !== 1 || ! Signer::isSigned($request->saveXML(), 'infEvento')) {
            return;
        }
        foreach (['chNFe' => $key, 'tpAmb' => '2', 'tpEvento' => '110111', 'nSeqEvento' => '1', 'cOrgao' => substr($key, 0, 2)] as $field => $expected) {
            if ($q($requestBase.'/n:'.$field) !== $expected || $r($responseBase.'/n:'.$field) !== $expected) {
                return;
            }
        }
        $cert = '-----BEGIN CERTIFICATE-----'.PHP_EOL.chunk_split($q('//s:X509Certificate'), 64, PHP_EOL).'-----END CERTIFICATE-----';
        if (! hash_equals((string) $this->credential->certificate_fingerprint, (string) openssl_x509_fingerprint($cert, 'sha256'))) {
            return;
        }
        throw new FiscalTransportException('cancellation_rejected_'.$code);
    }

    public function invalidate(int $series, int $first, int $last, int $year, string $reason, string $operationId): ProviderResult
    {
        throw new FiscalTransportException('direct_invalidation_not_enabled');
    }

    public function xml(string $reference): string
    {
        $result = $this->consult($reference);
        if ($result->state !== DocumentState::Authorized || ! $result->xml) {
            throw new FiscalTransportException('authorized_xml_unavailable');
        }
        return $result->xml;
    }

    public function danfe(string $reference): string
    {
        try {
            return (new Danfce($this->xml($reference)))->render();
        } catch (FiscalTransportException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new FiscalTransportException('danfe_render_failed');
        }
    }

    private function parse(#[\SensitiveParameter] string $xml): \DOMDocument
    {
        $dom = new \DOMDocument;
        $dom->preserveWhiteSpace = false;
        if ($xml === '' || strlen($xml) > 8 * 1024 * 1024 || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false || ! @$dom->loadXML($xml, LIBXML_NONET)) {
            throw new FiscalTransportException('sefaz_xml_invalid');
        }
        return $dom;
    }

    private function xpath(\DOMDocument $dom): \DOMXPath
    {
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $xp->registerNamespace('s', 'http://www.w3.org/2000/09/xmldsig#');
        return $xp;
    }
}
