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
