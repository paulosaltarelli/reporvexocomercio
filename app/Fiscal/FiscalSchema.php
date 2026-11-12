<?php

namespace App\Fiscal;

use NFePHP\Common\Validator;

final class FiscalSchema
{
    public const PACKAGE = 'PL_010_V1.30';

    public function validate(#[\SensitiveParameter] string $xml, string $package=self::PACKAGE): void
    {
        try {
            if(!in_array($package,[self::PACKAGE,'PL_010_V1.21','PL_010_V1','PL_009_V4'],true)) throw new \RuntimeException();
            $path = base_path('vendor/nfephp-org/sped-nfe/schemes/'.$package.'/procNFe_v4.00.xsd');
            if (! is_file($path) || ! Validator::isValid($xml, $path)) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            throw new FiscalTransportException('authorization_schema_invalid');
        }
    }
}
