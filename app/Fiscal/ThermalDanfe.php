<?php

namespace App\Fiscal;

use NFePHP\DA\NFe\Danfce;

final class ThermalDanfe extends Danfce
{
    protected function pagType($type)
    {
        // The pinned renderer predates the separate payment code for static Pix.
        return (int) $type === 20 ? 'PAGAMENTO INSTANTÂNEO (PIX ESTÁTICO)' : parent::pagType($type);
    }
}
