<?php

namespace Tests\Unit;

use App\Services\PurchaseInvoice;
use App\Services\PurchaseInvoiceXml;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PurchaseInvoiceTest extends TestCase
{
    public function test_invoice_separates_credit_expense_and_acquisition_and_allocates_every_cent(): void
    {
        $items = [
            ['quantity' => '1', 'unit_cost' => '10', 'taxes' => [
                'icms' => ['amount' => '1.80', 'composition' => 'included', 'treatment' => 'credit'],
                'pis' => ['amount' => '0.10', 'composition' => 'included', 'treatment' => 'expense'],
                'cofins' => ['amount' => '0.20', 'composition' => 'included', 'treatment' => 'cost']]],
            ['quantity' => '2', 'unit_cost' => '10', 'adjustments' => ['discount' => '1.00'], 'taxes' => [
                'ipi' => ['amount' => '1.00', 'composition' => 'added', 'treatment' => 'credit'],
                'icms_st' => ['amount' => '2.00', 'composition' => 'added', 'treatment' => 'cost']]],
        ];
        $invoice = PurchaseInvoice::calculate($items, ['freight' => '1.00', 'discount' => '0.10', 'declared_total' => '32.90']);
        $this->assertSame(['payable' => '32.90', 'acquisition' => '30.00', 'credit' => '2.80', 'expense' => '0.10'], $invoice['totals']);
        $this->assertSame('0.33', $invoice['lines'][0]['freight']);
        $this->assertSame('0.67', $invoice['lines'][1]['freight']);
        $this->assertSame('1.07', $invoice['lines'][1]['discount']);
    }

    private function invoiceXml(): string
    {
        return '<NFe xmlns="http://www.portalfiscal.inf.br/nfe"><infNFe Id="NFe35261111222333000181550010000000181123456781"><ide><mod>55</mod><serie>1</serie><nNF>18</nNF><dhEmi>2026-11-01T10:00:00-03:00</dhEmi></ide>
            <emit><CNPJ>11222333000181</CNPJ><xNome>Fornecedor teste</xNome></emit><dest><CNPJ>11444777000161</CNPJ></dest>
            <det nItem="1"><prod><cProd>X</cProd><uCom>CX</uCom><xProd>Caixa</xProd><qCom>100.0000</qCom><vUnCom>1.2400000000</vUnCom><vProd>124.00</vProd><vFrete>3.00</vFrete></prod>
            <imposto><IPI><IPITrib><vIPI>2.00</vIPI></IPITrib></IPI><ICMS><ICMS00><vICMS>1.50</vICMS></ICMS00></ICMS></imposto></det>
            <total><ICMSTot><vNF>129.00</vNF></ICMSTot></total></infNFe></NFe>';
    }

    private function xmlWithReform(string $total): string
    {
        $group = '<IBSCBS><CST>000</CST><cClassTrib>000001</cClassTrib><gIBSCBS><gIBSUF><vIBSUF>0.06</vIBSUF></gIBSUF><gIBSMun><vIBSMun>0.06</vIBSMun></gIBSMun><vIBS>0.12</vIBS><gCBS><vCBS>4.00</vCBS></gCBS></gIBSCBS></IBSCBS>';
        $totals = '<IBSCBSTot><gIBS><gIBSUF><vIBSUF>0.06</vIBSUF></gIBSUF><gIBSMun><vIBSMun>0.06</vIBSMun></gIBSMun><vIBS>0.12</vIBS></gIBS><gCBS><vCBS>4.00</vCBS></gCBS></IBSCBSTot>';
        return str_replace(['</imposto></det>', '</total>'], [
            '<IS><vIS>2.00</vIS></IS>'.$group.'</imposto><vItem>'.$total.'</vItem></det>',
            '<ISTot><vIS>2.00</vIS></ISTot>'.$totals.'<vNFTot>'.$total.'</vNFTot></total>',
        ], $this->invoiceXml());
    }

    public function test_reform_tax_disclosure_with_no_change_to_the_supplier_total_is_informational(): void
    {
        $preview = PurchaseInvoiceXml::preview($this->xmlWithReform('129.00'), '11444777000161');
        $items = $preview['items'];
        $items[0]['taxes']['ipi']['treatment'] = 'cost';
        $items[0]['taxes']['icms']['treatment'] = 'cost';
        $invoice = PurchaseInvoice::calculate($items, $preview['invoice']);
        $this->assertSame('000001', $invoice['lines'][0]['rtc_codes']['classification']);
        $this->assertSame('reported', $invoice['lines'][0]['taxes']['cbs']['composition']);
        $this->assertSame(['ibs_state'=>'0.06','ibs_city'=>'0.06','cbs'=>'4.00','is'=>'2.00'], $invoice['reform_totals']);
        $this->assertSame(['payable'=>'129.00','acquisition'=>'129.00','credit'=>'0.00','expense'=>'0.00'], $invoice['totals']);
        $this->expectException(ValidationException::class);
        $items[0]['taxes']['cbs']['treatment'] = 'credit';
        PurchaseInvoice::calculate($items, $preview['invoice']);
    }

    public function test_reform_tax_added_to_the_document_total_is_recorded_once_with_explicit_treatment(): void
    {
        $xml = str_replace(['35261111222333000181550010000000181123456781','2026-11-01'],
            ['35270111222333000181550010000000181123456780','2027-01-01'], $this->xmlWithReform('135.12'));
        $preview = PurchaseInvoiceXml::preview($xml, '11444777000161');
        $this->assertSame('135.12', $preview['invoice']['declared_total']);
        $this->assertSame(['amount'=>'4.00', 'composition'=>'added'], $preview['items'][0]['taxes']['cbs']);
        foreach ($preview['items'][0]['taxes'] as $code=>&$tax) {
            $tax['treatment'] = $code === 'cbs' ? 'credit' : 'cost';
        }
        unset($tax);
        $invoice = PurchaseInvoice::calculate($preview['items'], $preview['invoice']);
        $this->assertSame(['payable'=>'135.12','acquisition'=>'131.12','credit'=>'4.00','expense'=>'0.00'], $invoice['totals']);
    }

    public function test_positive_reform_taxes_without_a_distinct_supplier_total_in_2027_require_manual_review(): void
    {
        $xml = str_replace(['35261111222333000181550010000000181123456781','2026-11-01'],
            ['35270111222333000181550010000000181123456780','2027-01-01'], $this->xmlWithReform('129.00'));
        foreach ([$xml, str_replace('<vNFTot>129.00</vNFTot>', '', $xml)] as $case) {
            try {
                PurchaseInvoiceXml::preview($case, '11444777000161');
                $this->fail('A entrada de 2027 precisa identificar a cobrança dos tributos.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('Confira a composição da entrada manualmente', $exception->errors()['invoice_xml'][0]);
            }
        }
    }

    public function test_reform_mismatch_in_item_aggregate_or_note_total_requires_manual_review(): void
    {
        $xml = $this->xmlWithReform('135.12');
        $cases = [
            str_replace('<vIBS>0.12', '<vIBS>0.13', $xml),
            str_replace('<gCBS><vCBS>4.00</vCBS></gCBS></IBSCBSTot>', '<gCBS><vCBS>4.02</vCBS></gCBS></IBSCBSTot>', $xml),
            str_replace('<vNFTot>135.12', '<vNFTot>137.00', $xml),
            str_replace('<vItem>135.12', '<vItem>131.00', $xml),
            str_replace('</IBSCBS>', '<gIBSCBSMono/></IBSCBS>', $xml),
        ];
        foreach ($cases as $case) {
            try {
                PurchaseInvoiceXml::preview($case, '11444777000161');
                $this->fail('Divergência fiscal aceita.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('invoice_xml', $exception->errors());
            }
        }
    }

    public function test_xml_preview_matches_supplier_item_components_and_destination(): void
    {
        $xml = $this->invoiceXml();
        $preview = PurchaseInvoiceXml::preview($xml, '11.444.777/0001-61');
        $this->assertSame('129.00', $preview['invoice']['declared_total']);
        $this->assertSame('11222333000181', $preview['issuer_document']);
        $this->assertSame(['amount' => '2.00', 'composition' => 'added'], $preview['items'][0]['taxes']['ipi']);
        $this->assertSame('included', $preview['items'][0]['taxes']['icms']['composition']);
        $this->assertSame('CX', $preview['items'][0]['source_unit']);
        $this->expectException(ValidationException::class);
        PurchaseInvoiceXml::preview($xml, '11222333000181');
    }

    public function test_xml_preview_refuses_unreconciled_amounts(): void
    {
        $this->expectException(ValidationException::class);
        PurchaseInvoiceXml::preview(str_replace('129.00', '130.00', $this->invoiceXml()), '11444777000161');
    }

    public function test_positive_tax_requires_explicit_treatment(): void
    {
        $this->expectException(ValidationException::class);
        PurchaseInvoice::calculate([['quantity' => '1', 'unit_cost' => '10', 'taxes' => ['ipi' => ['amount' => '1', 'composition' => 'added']]]], ['declared_total' => '11']);
    }

    public function test_invoice_rejects_disagreement_with_declared_total(): void
    {
        $this->expectException(ValidationException::class);
        PurchaseInvoice::calculate([['quantity' => '1', 'unit_cost' => '10']], ['declared_total' => '9.99']);
    }
}
