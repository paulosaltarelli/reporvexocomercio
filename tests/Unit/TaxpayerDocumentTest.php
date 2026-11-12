<?php

namespace Tests\Unit;

use App\Fiscal\Snapshot;
use App\Fiscal\TaxpayerDocument;
use PHPUnit\Framework\TestCase;

class TaxpayerDocumentTest extends TestCase
{
    public function test_numeric_and_official_rfb_alphanumeric_example(): void
    {
        $this->assertTrue(TaxpayerDocument::cnpj('11.222.333/0001-81'));
        $this->assertTrue(TaxpayerDocument::cnpj('12.ABC.345/01DE-35'));
        $this->assertTrue(TaxpayerDocument::cnpj('12.abc.345/01de-35'));
        $this->assertFalse(TaxpayerDocument::cnpj('12.ABC.345/01DE-36'));
        $this->assertFalse(TaxpayerDocument::cnpj('00.000.000/0000-00'));
        $this->assertFalse(TaxpayerDocument::cnpj('12ABC34501DE35<script>'));
        $this->assertFalse(TaxpayerDocument::cnpj('12ABC34501DEAA'));
    }

    public function test_cpf_dv_and_repeated_digits(): void
    {
        $this->assertTrue(TaxpayerDocument::cpf('529.982.247-25'));
        $this->assertFalse(TaxpayerDocument::cpf('529.982.247-26'));
        $this->assertFalse(TaxpayerDocument::cpf('111.111.111-11'));
    }

    public function test_hash_survives_json_object_key_reordering_but_detects_value_changes(): void
    {
        $a = ['issuer' => ['name' => 'Empresa', 'cnpj' => '12ABC34501DE35'], 'items' => [['total' => '1.00', 'id' => 1]]];
        $b = ['items' => [['id' => 1, 'total' => '1.00']], 'issuer' => ['cnpj' => '12ABC34501DE35', 'name' => 'Empresa']];
        $this->assertSame(Snapshot::hash($a), Snapshot::hash($b));
        $b['items'][0]['total'] = '2.00';
        $this->assertNotSame(Snapshot::hash($a), Snapshot::hash($b));
    }
}
