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
        $total = self::decimal($get($info, 'n:total/n:ICMSTot/n:vNF'), 2);
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
            $payable = bcadd($payable, $gross, 2);
            $adjustments = [];
            foreach (['freight' => 'vFrete', 'insurance' => 'vSeg', 'expenses' => 'vOutro', 'discount' => 'vDesc'] as $field => $tag) {
                $amount = self::decimal($get($detail, 'n:prod/n:'.$tag) ?: '0', 2);
                $adjustments[$field] = $amount;
                $payable = $field === 'discount' ? bcsub($payable, $amount, 2) : bcadd($payable, $amount, 2);
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
                    $payable = bcadd($payable, $amount, 2);
                }
            }
            $items[] = ['quantity' => $qty, 'unit_cost' => $price, 'adjustments' => $adjustments, 'taxes' => $taxes,
                'source_code' => $get($detail, 'n:prod/n:cProd'), 'source_name' => $get($detail, 'n:prod/n:xProd'), 'source_unit' => $get($detail, 'n:prod/n:uCom')];
        }
        if (bccomp($payable, $total, 2) !== 0) {
            self::invalid('Os componentes suportados somam R$ '.$payable.' e divergem do total da NF-e. Confira a entrada manualmente.');
        }

        return ['issuer_document' => $emitter, 'issuer_name' => $get($info, 'n:emit/n:xNome'), 'items' => $items,
            'invoice' => ['number' => $number, 'series' => $series, 'access_key' => substr($key, 3), 'issued_on' => $issued, 'declared_total' => $total]];
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
