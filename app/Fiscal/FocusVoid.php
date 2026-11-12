<?php

namespace App\Fiscal;

use App\Models\FiscalCredential;
use App\Models\FiscalIssuer;

final class FocusVoid
{
    public function perform(FiscalIssuer $issuer, #[\SensitiveParameter] FiscalCredential $credential, object $void, bool $submit): array
    {
        if ($issuer->environment !== 'homologation') {
            throw new FiscalTransportException('production_disabled');
        }
        $token = (string) ($credential->secrets['emission_token'] ?? '');
        if ($submit) {
            $result = app(FocusClient::class)->request('POST', '/nfce/inutilizacao', $token, ['cnpj' => $issuer->document, 'serie' => (string) $void->series, 'numero_inicial' => (string) $void->first_number, 'numero_final' => (string) $void->last_number, 'justificativa' => $void->reason]);
        } else {
            $rows = app(FocusClient::class)->request('GET', '/nfce/inutilizacoes', $token, ['cnpj' => $issuer->document, 'numero_inicial' => $void->first_number, 'numero_final' => $void->last_number]);
            $result = null;
            foreach ($rows as $row) {
                if (is_array($row) && (int) ($row['serie'] ?? -1) === $void->series && (int) ($row['numero_inicial'] ?? 0) === $void->first_number && (int) ($row['numero_final'] ?? 0) === $void->last_number) {
                    $result = $row;
                    break;
                }
            }
            if (! $result) {
                throw new FiscalTransportException('void_consultation_required');
            }
        }
        if (($result['status'] ?? '') !== 'autorizado' || (string) ($result['status_sefaz'] ?? '') !== '102') {
            if (($result['status'] ?? '') === 'erro_autorizacao') {
                return ['state' => 'rejected', 'code' => (string) ($result['status_sefaz'] ?? ''), 'xml' => null, 'protocol' => null];
            }
            throw new FiscalTransportException('void_result_uncertain');
        }
        $xml = app(FocusClient::class)->download((string) ($result['caminho_xml'] ?? ''), $token);
        $dom = new \DOMDocument;
        if (stripos($xml, '<!DOCTYPE') !== false || ! @$dom->loadXML($xml, LIBXML_NONET)) {
            throw new FiscalTransportException('void_xml_invalid');
        }
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $base = '//n:retInutNFe/n:infInut';
        $value = fn ($q) => $xp->evaluate('string('.$base.'/'.$q.')');
        if ($value('n:cStat') !== '102' || $value('n:tpAmb') !== '2' || $value('n:CNPJ') !== $issuer->document || (int) $value('n:mod') !== 65 || (int) $value('n:serie') !== $void->series || (int) $value('n:nNFIni') !== $void->first_number || (int) $value('n:nNFFin') !== $void->last_number || ! $value('n:nProt')) {
            throw new FiscalTransportException('void_xml_invalid');
        }

        return ['state' => 'voided', 'code' => '102', 'xml' => $xml, 'protocol' => $value('n:nProt')];
    }
}
