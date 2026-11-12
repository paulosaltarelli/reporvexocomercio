<?php

namespace App\Fiscal;

use App\Models\FiscalCredential;
use App\Models\FiscalIssuer;
use NFePHP\DA\NFe\Danfce;

final class FocusProvider implements FiscalProvider
{
    public function __construct(private FiscalIssuer $issuer, #[\SensitiveParameter] private FiscalCredential $credential, #[\SensitiveParameter] private array $payload = [])
    {
        if ($issuer->environment !== 'homologation' || $issuer->provider !== 'focus' || (int) $credential->issuer_id !== (int) $issuer->id) {
            throw new FiscalTransportException('provider_context_invalid');
        }
    }

    private function token(): string
    {
        return (string) ($this->credential->secrets['emission_token'] ?? '');
    }

    public function issue(string $reference, #[\SensitiveParameter] array $frozenPayload): ProviderResult
    {
        $this->payload = $frozenPayload + array_intersect_key($this->payload, ['_certificate_fingerprint' => true,'_schema_package'=>true]);

        return $this->normalize(app(FocusClient::class)->request('POST', '/nfce?ref='.rawurlencode($reference).'&completa=1', $this->token(), $frozenPayload), $reference);
    }

    public function consult(string $reference): ProviderResult
    {
        return $this->normalize(app(FocusClient::class)->request('GET', '/nfce/'.rawurlencode($reference), $this->token(), ['completa' => 1]), $reference);
    }

    private function normalize(array $result, string $reference): ProviderResult
    {
        if (($result['ref'] ?? null) !== $reference || TaxpayerDocument::normalize((string) ($result['cnpj_emitente'] ?? '')) !== $this->issuer->document) {
            throw new FiscalTransportException('provider_identity_mismatch');
        }
        if (! empty($result['contingencia_offline']) || ! empty($result['contingencia_offline_efetivada'])) {
            throw new FiscalTransportException('contingency_requires_reconciliation');
        }
        $code = isset($result['status_sefaz']) ? (string) $result['status_sefaz'] : null;
        if (($result['status'] ?? '') === 'autorizado' && $code === '100') {
            $xml = app(FocusClient::class)->download((string) ($result['caminho_xml_nota_fiscal'] ?? ''), $this->token());
            $evidence = app(XmlEvidence::class)->authorization($xml, $this->payload, $this->credential);
            if (preg_replace('/^NFe/', '', (string) ($result['chave_nfe'] ?? '')) !== $evidence['key']) {
                throw new FiscalTransportException('provider_key_mismatch');
            }

            return new ProviderResult(DocumentState::Authorized, '100', $evidence['key'], $evidence['protocol'], $xml, $evidence['authorized_at']);
        }
        if (($result['status'] ?? '') === 'cancelado') {
            // Preserve official event XML/protocol, never infer cancellation from HTTP success.
            $path = $result['caminho_xml_cancelamento'] ?? null;
            if (! $path) {
                throw new FiscalTransportException('cancellation_event_missing');
            }
            $xml = app(FocusClient::class)->download($path, $this->token());
            $dom = new \DOMDocument;
            if (stripos($xml, '<!DOCTYPE') !== false || ! @$dom->loadXML($xml, LIBXML_NONET)) {
                throw new FiscalTransportException('cancellation_xml_invalid');
            }
            $xp = new \DOMXPath($dom);
            $xp->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
            $value = fn ($q) => $xp->evaluate('string('.$q.')');
            $base = '//n:retEvento/n:infEvento';
            if (! in_array($value($base.'/n:cStat'), ['135', '155'], true) || $value($base.'/n:tpEvento') !== '110111' || $value($base.'/n:tpAmb') !== '2' || ! $value($base.'/n:nProt') || $value($base.'/n:chNFe') !== ($this->payload['_access_key'] ?? '')) {
                throw new FiscalTransportException('cancellation_xml_invalid');
            }

            return new ProviderResult(DocumentState::Cancelled, $value($base.'/n:cStat'), $value($base.'/n:chNFe'), $value($base.'/n:nProt'), $xml);
        }
        if (($result['status'] ?? '') === 'erro_autorizacao' && $code && $code !== '100') {
            return new ProviderResult(DocumentState::Rejected, $code);
        }

        return new ProviderResult(DocumentState::Uncertain, $code);
    }

    public function cancel(string $reference, string $reason, string $operationId): ProviderResult
    {
        app(FocusClient::class)->request('DELETE', '/nfce/'.rawurlencode($reference), $this->token(), ['justificativa' => $reason]);

        return $this->consult($reference);
    }

    public function invalidate(int $series, int $first, int $last, int $year, string $reason, string $operationId): ProviderResult
    {
        $void = (object) ['series' => $series, 'first_number' => $first, 'last_number' => $last, 'year' => $year, 'reason' => $reason];
        $result = app(FocusVoid::class)->perform($this->issuer, $this->credential, $void, true);

        return new ProviderResult($result['state'] === 'voided' ? DocumentState::Voided : DocumentState::Rejected, $result['code'], null, $result['protocol'], $result['xml']);
    }

    public function xml(string $reference): string
    {
        return $this->consult($reference)->xml ?? throw new FiscalTransportException('xml_unavailable');
    }

    public function danfe(string $reference): string
    {
        return (new Danfce($this->xml($reference)))->render();
    }
}
