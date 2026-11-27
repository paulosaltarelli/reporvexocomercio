<?php

namespace App\Fiscal;

use NFePHP\Common\Validator;

final class FiscalSchema
{
    public const PACKAGE = 'PL_010_V1.30';
    public const NFCE_PACKAGE = 'PL_010h_v1.03';
    public const NFCE_SCHEMA_DIRECTORY = __DIR__.'/Nfce/schemas/'.self::NFCE_PACKAGE;

    public function validateSignedNfce(#[\SensitiveParameter] string $xml): void
    {
        $this->validateAgainst($xml, self::NFCE_SCHEMA_DIRECTORY.'/nfe_v4.00.xsd', 'nfce_signed_schema_invalid');
    }

    public function validate(#[\SensitiveParameter] string $xml, string $package = self::PACKAGE): void
    {
        if ($package === self::NFCE_PACKAGE) {
            $path = self::NFCE_SCHEMA_DIRECTORY.'/procNFe_v4.00.xsd';
        } elseif (in_array($package, [self::PACKAGE, 'PL_010_V1.21', 'PL_010_V1', 'PL_009_V4'], true)) {
            $path = base_path('vendor/nfephp-org/sped-nfe/schemes/'.$package.'/procNFe_v4.00.xsd');
        } else {
            throw new FiscalTransportException('authorization_schema_invalid');
        }
        $this->validateAgainst($xml, $path, 'authorization_schema_invalid');
    }

    private function validateAgainst(#[\SensitiveParameter] string $xml, string $path, string $failure): void
    {
        try {
            if (! is_file($path) || ! Validator::isValid($xml, $path)) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            throw new FiscalTransportException($failure);
        }
    }
}
