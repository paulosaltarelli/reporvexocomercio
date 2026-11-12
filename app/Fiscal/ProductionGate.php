<?php

namespace App\Fiscal;

use DomainException;

final class ProductionGate
{
    public function assertAllowed(Environment $environment, bool $demonstration, bool $approved): void
    {
        if ($environment === Environment::Production && ($demonstration || ! $approved)) {
            throw new DomainException('Produção fiscal bloqueada: confirme empresa real, homologação e autorização explícita do proprietário.');
        }
    }
}
