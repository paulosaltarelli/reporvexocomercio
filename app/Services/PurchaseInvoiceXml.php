<?php

namespace App\Services;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Validation\ValidationException;

final class PurchaseInvoiceXml
{
    public static function preview(string $xml, string $companyDocument): array
    {
        if ($xml === '' || strlen($xml) > 2097152 || str_contains($xml, "\0") || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            self::invalid('Use um XML NF-e de até 2 MB, sem declaração de entidades.');
        }
        $dom = new DOMDocument;
        if (! @$dom->loadXML($xml, LIBXML_NONET) || $dom->doctype) {
            self::invalid('XML inválido.');
        }
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $nodes = $xp->query('/n:nfeProc/n:NFe/n:infNFe | /n:NFe/n:infNFe');
        if ($nodes->length !== 1) {
            self::invalid('Selecione uma única NF-e modelo 55.');
        }
        $info = $nodes->item(0);
        $get = static fn (DOMNode $node, string $path): string => trim((string) $xp->evaluate('string('.$path.')', $node));
        $emitter = strtoupper($get($info, 'n:emit/n:CNPJ'));
        $dest = strtoupper($get($info, 'n:dest/n:CNPJ'));
        if ($dest === '' || $dest !== strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $companyDocument))) {
            self::invalid('O CNPJ do destinatário deve corresponder ao CNPJ cadastrado na empresa.');
        }
        $key = $get($info, '@Id');
        if (! str_starts_with($key, 'NFe') || $get($info, 'n:ide/n:mod') !== '55') {
            self::invalid('Use uma NF-e modelo 55 com chave identificada.');
        }
        $number = $get($info, 'n:ide/n:nNF');
        $series = $get($info, 'n:ide/n:serie');
        $issued = substr($get($info, 'n:ide/n:dhEmi'), 0, 10);
        FinancialService::date($issued, 'invoice_xml');
        PurchaseInvoice::validateKey(substr($key, 3), $emitter, $series, $number, $issued);
        if ($dom->documentElement->localName === 'nfeProc') {
            $protocol = $xp->query('/n:nfeProc/n:protNFe/n:infProt');
            if ($protocol->length !== 1 || ! in_array($get($protocol->item(0), 'n:cStat'), ['100', '150'], true)
                || $get($protocol->item(0), 'n:chNFe') !== substr($key, 3)) {
                self::invalid('A chave ou o status do protocolo diverge da NF-e.');
            }
        }
        $legacyTotal = self::decimal($get($info, 'n:total/n:ICMSTot/n:vNF'), 2);
        $documentTotal = $get($info, 'n:total/n:vNFTot');
        $total = $documentTotal === '' ? $legacyTotal : self::decimal($documentTotal, 2);
        $reformTotals = array_fill_keys(array_keys(PurchaseInvoice::REFORM_TAXES), '0.00');
        $lineAmounts = [];
        $items = [];
        $payable = '0.00';
        $details = $xp->query('n:det', $info);
        if ($details->length < 1 || $details->length > 50) {
            self::invalid('O recebimento aceita de 1 a 50 itens.');
        }
        foreach ($details as $detail) {
            if ($get($detail, 'n:prod/n:indTot') === '0') {
                self::invalid('Esta nota contém item fora do total e requer conferência manual.');
            }
            $qty = self::decimal($get($detail, 'n:prod/n:qCom'), 3);
            $price = self::decimal($get($detail, 'n:prod/n:vUnCom'), 4);
            $gross = self::decimal($get($detail, 'n:prod/n:vProd'), 2);
            if (bccomp(Decimal::round(bcmul($qty, $price, 7)), $gross, 2) !== 0) {
                self::invalid('Quantidade, valor unitário e valor dos produtos divergem no XML.');
            }
            $linePayable = $gross;
            $adjustments = [];
            foreach (['freight' => 'vFrete', 'insurance' => 'vSeg', 'expenses' => 'vOutro', 'discount' => 'vDesc'] as $field => $tag) {
                $amount = self::decimal($get($detail, 'n:prod/n:'.$tag) ?: '0', 2);
                $adjustments[$field] = $amount;
                $linePayable = $field === 'discount' ? bcsub($linePayable, $amount, 2) : bcadd($linePayable, $amount, 2);
            }
            $taxes = [];
            foreach (['icms' => 'ICMS/*/n:vICMS', 'icms_st' => 'ICMS/*/n:vICMSST', 'ipi' => 'IPI/n:IPITrib/n:vIPI', 'pis' => 'PIS/*/n:vPIS', 'cofins' => 'COFINS/*/n:vCOFINS'] as $code => $path) {
                $amount = self::decimal($get($detail, 'n:imposto/n:'.$path) ?: '0', 2);
                if (bccomp($amount, '0', 2) === 0) {
                    continue;
                }
                $added = in_array($code, ['icms_st', 'ipi'], true);
                $taxes[$code] = ['amount' => $amount, 'composition' => $added ? 'added' : 'included'];
                if ($added) {
                    $linePayable = bcadd($linePayable, $amount, 2);
                }
            }
            $payable = bcadd($payable, $linePayable, 2);
            $itemAmount = $get($detail, 'n:vItem');
            $lineAmounts[] = [$linePayable, $itemAmount === '' ? null : self::decimal($itemAmount, 2)];
            $rtc = $xp->query('n:imposto/n:IBSCBS/*[not(self::n:CST or self::n:cClassTrib or self::n:indDoacao or self::n:gIBSCBS)]', $detail);
            if ($rtc->length > 0) {
                self::invalid('A composição especial de IBS/CBS desta nota requer conferência manual.');
            }
            $reformTaxes = self::reformItem($get, $detail);
            foreach ($reformTaxes as $code => $tax) {
                $reformTotals[$code] = bcadd($reformTotals[$code], $tax['amount'], 2);
            }
            $taxes += $reformTaxes;
            $codes = [];
            foreach (['cst'=>'IBSCBS/n:CST', 'classification'=>'IBSCBS/n:cClassTrib', 'is_cst'=>'IS/n:CSTIS', 'is_classification'=>'IS/n:cClassTribIS'] as $code=>$path) {
                $value = $get($detail, 'n:imposto/n:'.$path);
                if ($value === '') continue;
                $digits = str_contains($code, 'classification') ? 6 : 3;
                if (!preg_match('/^\d{'.$digits.'}$/D', $value)) self::invalid('Código de classificação tributária inválido no XML.');
                $codes[$code] = $value;
            }
            $items[] = ['rtc_codes' => $codes, 'quantity' => $qty, 'unit_cost' => $price, 'adjustments' => $adjustments, 'taxes' => $taxes,
                'source_code' => $get($detail, 'n:prod/n:cProd'), 'source_name' => $get($detail, 'n:prod/n:xProd'), 'source_unit' => $get($detail, 'n:prod/n:uCom')];
        }
        if (bccomp($payable, $legacyTotal, 2) !== 0) {
            self::invalid('Os componentes suportados somam R$ '.$payable.' e divergem do total anterior aos tributos da reforma (vNF). Confira a entrada manualmente.');
        }
        self::checkReformTotals($get, $info, $reformTotals);
        $reformSum = '0.00';
        foreach ($reformTotals as $amount) $reformSum = bcadd($reformSum, $amount, 2);
        $charged = bccomp($total, $legacyTotal, 2) !== 0;
        if ($issued >= '2027-01-01' && bccomp($reformSum, '0', 2) > 0 && !$charged) {
            self::invalid('O XML de 2027 em diante informa tributos da reforma, mas o total vNFTot está ausente ou coincide com vNF. Confira a composição da entrada manualmente.');
        }
        if ($charged && bccomp(bcadd($legacyTotal, $reformSum, 2), $total, 2) !== 0) {
            self::invalid('O total vNFTot diverge dos valores de IBS, CBS e IS destacados. Confira a composição da nota manualmente.');
        }
        foreach ($items as $index=>&$item) {
            [$linePayable, $declaredLine] = $lineAmounts[$index];
            if ($charged) {
                foreach (array_intersect_key($item['taxes'], PurchaseInvoice::REFORM_TAXES) as $code=>$tax) {
                    $item['taxes'][$code]['composition'] = 'added';
                    $linePayable = bcadd($linePayable, $tax['amount'], 2);
                }
            }
            if ($declaredLine !== null && bccomp($linePayable, $declaredLine, 2) !== 0) {
                self::invalid('O valor total de um item (vItem) diverge de sua composição. Confira a entrada manualmente.');
            }
        }
        unset($item);

        return ['issuer_document' => $emitter, 'issuer_name' => $get($info, 'n:emit/n:xNome'), 'items' => $items,
            'invoice' => ['number' => $number, 'series' => $series, 'access_key' => substr($key, 3), 'issued_on' => $issued, 'declared_total' => $total]];
    }

    private static function reformItem(callable $get, DOMNode $detail): array
    {
        $group = 'n:imposto/n:IBSCBS/n:gIBSCBS/';
        $paths = ['ibs_state'=>$group.'n:gIBSUF/n:vIBSUF', 'ibs_city'=>$group.'n:gIBSMun/n:vIBSMun',
            'cbs'=>$group.'n:gCBS/n:vCBS', 'is'=>'n:imposto/n:IS/n:vIS'];
        $taxes = [];
        foreach ($paths as $code=>$path) {
            $value = $get($detail, $path);
            if ($value !== '') $taxes[$code] = ['amount'=>self::decimal($value, 2), 'composition'=>'reported'];
        }
        $ibs = $get($detail, $group.'n:vIBS');
        $components = bcadd($taxes['ibs_state']['amount'] ?? '0', $taxes['ibs_city']['amount'] ?? '0', 2);
        if (($ibs !== '' || bccomp($components, '0', 2) !== 0)
            && ($ibs === '' || bccomp(self::decimal($ibs, 2), $components, 2) !== 0)) {
            self::invalid('O IBS do item diverge das parcelas estadual e municipal. Confira a entrada manualmente.');
        }
        return $taxes;
    }

    private static function checkReformTotals(callable $get, DOMNode $info, array $totals): void
    {
        $paths = ['ibs_state'=>'IBSCBSTot/n:gIBS/n:gIBSUF/n:vIBSUF', 'ibs_city'=>'IBSCBSTot/n:gIBS/n:gIBSMun/n:vIBSMun',
            'ibs'=>'IBSCBSTot/n:gIBS/n:vIBS', 'cbs'=>'IBSCBSTot/n:gCBS/n:vCBS', 'is'=>'ISTot/n:vIS'];
        $totals['ibs'] = bcadd($totals['ibs_state'], $totals['ibs_city'], 2);
        foreach ($paths as $code=>$path) {
            $value = $get($info, 'n:total/n:'.$path);
            if (bccomp(self::decimal($value === '' ? '0' : $value, 2), $totals[$code], 2) !== 0) {
                self::invalid('O total de '.(PurchaseInvoice::REFORM_TAXES[$code] ?? 'IBS').' diverge dos itens da nota. Confira o XML.');
            }
        }
    }

    private static function decimal(string $value, int $scale): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return Decimal::input($value,$scale,'invoice_xml','0',$scale === 4 ? '999999999.9999' : '999999999999.99');
    }

    private static function invalid(string $message): never
    {
        throw ValidationException::withMessages(['invoice_xml' => $message]);
    }
}
