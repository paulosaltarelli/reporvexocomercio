<?php

namespace App\Fiscal;

use App\Models\FiscalCredential;
use NFePHP\NFe\Make;
use NFePHP\Common\Keys;

/** No network transmission: freeze and persist this signed result before calling issue. */
final class NfceXmlBuilder
{
    public function prepare(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] array $issuerSnapshot, array $configuration, #[\SensitiveParameter] FiscalCredential $credential, int $number, int $series, ?string $numericCode = null): array
    {
        $issuerSnapshot['document'] = TaxpayerDocument::normalize((string) ($issuerSnapshot['document'] ?? ''));
        if ($number < 1 || $number > 999999999 || $series < 0 || $series > 999 || empty($payload['data_emissao']) || empty($payload['items']) || ($payload['cnpj_emitente'] ?? '') !== ($issuerSnapshot['document'] ?? '')) {
            throw new FiscalTransportException('nfce_snapshot_invalid');
        }
        // This pinned NFePHP version has numeric access-key/certificate support only.
        if (! preg_match('/^[0-9]{14}$/D', (string) $issuerSnapshot['document'])) {
            throw new FiscalTransportException('alphanumeric_cnpj_not_supported_by_direct_library');
        }
        if ($numericCode === null) {
            do {
                $numericCode = str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT);
            } while (! Keys::cNFIsValid($numericCode) || (int) $numericCode === $number);
        }
        if (! preg_match('/^[0-9]{8}$/D', $numericCode) || ! Keys::cNFIsValid($numericCode) || (int) $numericCode === $number) {
            throw new FiscalTransportException('numeric_code_invalid');
        }
        try {
            $make = new Make(FiscalSchema::PACKAGE);
            $make->setCalculationMethod(Make::METHOD_CALCULATION_V2);
            $make->taginfNFe((object) ['versao' => '4.00']);
            $make->tagide((object) [
                'cUF' => substr((string) $issuerSnapshot['municipality_code'], 0, 2),
                'cNF' => $numericCode, 'natOp' => $payload['natureza_operacao'],
                'mod' => 65, 'serie' => $series, 'nNF' => $number,
                'dhEmi' => $payload['data_emissao'], 'tpNF' => 1, 'idDest' => 1,
                'cMunFG' => $issuerSnapshot['municipality_code'], 'tpImp' => 4,
                'tpEmis' => 1, 'tpAmb' => 2, 'finNFe' => 1, 'indFinal' => 1,
                'indPres' => 1, 'indIntermed' => 0, 'procEmi' => 0, 'verProc' => 'Vendra 1.0',
            ]);
            $make->tagemit((object) [
                'CNPJ' => $issuerSnapshot['document'], 'xNome' => $issuerSnapshot['legal_name'],
                'xFant' => $issuerSnapshot['trade_name'] ?? null,
                'IE' => $issuerSnapshot['state_registration'], 'CRT' => $configuration['crt'],
            ]);
            $make->tagenderEmit((object) [
                'xLgr' => $issuerSnapshot['street'], 'nro' => $issuerSnapshot['number'],
                'xBairro' => $issuerSnapshot['district'], 'cMun' => $issuerSnapshot['municipality_code'],
                'xMun' => $issuerSnapshot['city'], 'UF' => $issuerSnapshot['state'],
                'CEP' => preg_replace('/\D/', '', (string) $issuerSnapshot['postal_code']),
                'cPais' => 1058, 'xPais' => 'BRASIL',
            ]);
            if (! empty($payload['cpf_destinatario']) || ! empty($payload['cnpj_destinatario'])) {
                $make->tagdest((object) ['CPF' => $payload['cpf_destinatario'] ?? null, 'CNPJ' => $payload['cnpj_destinatario'] ?? null, 'xNome' => $payload['nome_destinatario'], 'indIEDest' => 9]);
            }
            foreach ($payload['items'] as $line) {
                $this->item($make, $line);
            }
            $totals = ['vBC' => 'icms_base_calculo', 'vICMS' => 'icms_valor', 'vPIS' => 'pis_valor', 'vCOFINS' => 'cofins_valor', 'vTotTrib' => 'valor_total_tributos'];
            $total = [];
            foreach ($totals as $xml => $field) {
                $total[$xml] = '0.00';
                foreach ($payload['items'] as $line) {
                    $total[$xml] = bcadd($total[$xml], (string) ($line[$field] ?? '0'), 2);
                }
            }
            foreach (['vICMSDeson', 'vFCP', 'vBCST', 'vST', 'vFCPST', 'vFCPSTRet', 'vFrete', 'vSeg', 'vII', 'vIPI', 'vIPIDevol', 'vOutro'] as $field) {
                $total[$field] = '0.00';
            }
            $make->tagICMSTot((object) ($total + ['vProd' => $payload['valor_produtos'], 'vDesc' => $payload['valor_desconto'], 'vNF' => $payload['valor_total']]));
            $make->tagtransp((object) ['modFrete' => 9]);
            $make->tagpag((object) ['vTroco' => null]);
            foreach ($payload['formas_pagamento'] as $payment) {
                $make->tagdetPag((object) ['indPag' => 0, 'tPag' => $payment['forma_pagamento'], 'vPag' => $payment['valor_pagamento']]);
            }
            $xml = $make->getXML();
            if (! $xml || $make->getErrors()) {
                throw new FiscalTransportException('nfce_xml_invalid');
            }
            $signed = (new SefazTransport($issuerSnapshot, $configuration, $credential))->sign($xml);
            $dom = new \DOMDocument;
            if (! $dom->loadXML($signed, LIBXML_NONET)) {
                throw new FiscalTransportException('nfce_xml_invalid');
            }
            $key = substr($dom->getElementsByTagName('infNFe')->item(0)->getAttribute('Id'), 3);
            if (! preg_match('/^[0-9]{44}$/D', $key)) {
                throw new FiscalTransportException('access_key_invalid');
            }
            return ['xml' => $signed, 'access_key' => $key];
        } catch (FiscalTransportException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new FiscalTransportException('nfce_xml_invalid');
        }
    }

    private function item(Make $make, array $line): void
    {
        $n = (int) $line['numero_item'];
        $make->tagprod((object) [
            'item' => $n, 'cProd' => $line['codigo_produto'],
            'cEAN' => $line['codigo_barras_comercial'], 'xProd' => $line['descricao'],
            'NCM' => $line['codigo_ncm'], 'CEST' => $line['codigo_cest'] ?? null,
            'CFOP' => $line['cfop'], 'uCom' => $line['unidade_comercial'],
            'qCom' => $line['quantidade_comercial'], 'vUnCom' => $line['valor_unitario_comercial'],
            'vProd' => $line['valor_bruto'], 'cEANTrib' => $line['codigo_barras_tributavel'],
            'uTrib' => $line['unidade_tributavel'], 'qTrib' => $line['quantidade_tributavel'],
            'vUnTrib' => $line['valor_unitario_tributavel'],
            'vDesc' => bccomp((string) $line['valor_desconto'], '0', 2) > 0 ? $line['valor_desconto'] : null, 'indTot' => 1,
        ]);
        $make->tagimposto((object) ['item' => $n, 'vTotTrib' => $line['valor_total_tributos'] ?? '0.00']);
        $icms = ['item' => $n, 'orig' => $line['icms_origem'], 'modBC' => 3, 'vBC' => $line['icms_base_calculo'] ?? null, 'pICMS' => $line['icms_aliquota'] ?? null, 'vICMS' => $line['icms_valor'] ?? null];
        $cst = (string) $line['icms_situacao_tributaria'];
        if (strlen($cst) === 3) {
            $make->tagICMSSN((object) ($icms + ['CSOSN' => $cst]));
        } else {
            $make->tagICMS((object) ($icms + ['CST' => $cst]));
        }
        foreach (['pis' => 'PIS', 'cofins' => 'COFINS'] as $field => $tag) {
            $make->{'tag'.$tag}((object) ['item' => $n, 'CST' => $line[$field.'_situacao_tributaria'], 'vBC' => $line[$field.'_base_calculo'] ?? null, 'p'.$tag => $line[$field.'_aliquota'] ?? null, 'v'.$tag => $line[$field.'_valor'] ?? null]);
        }
        if (isset($line['ibs_cbs_situacao_tributaria'])) {
            $make->tagIBSCBS((object) [
                'item' => $n, 'CST' => $line['ibs_cbs_situacao_tributaria'],
                'cClassTrib' => $line['ibs_cbs_classificacao_tributaria'], 'vBC' => $line['ibs_cbs_base_calculo'],
                'gIBSUF_pIBSUF' => $line['ibs_uf_aliquota'], 'gIBSUF_vIBSUF' => $line['ibs_uf_valor'],
                'gIBSMun_pIBSMun' => $line['ibs_mun_aliquota'], 'gIBSMun_vIBSMun' => $line['ibs_mun_valor'],
                'gCBS_pCBS' => $line['cbs_aliquota'], 'gCBS_vCBS' => $line['cbs_valor'],
                'vIBS' => $line['ibs_valor_total'],
            ]);
        }
    }
}
