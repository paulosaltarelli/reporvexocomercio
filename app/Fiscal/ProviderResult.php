<?php

namespace App\Fiscal;

use InvalidArgumentException;

/** Normalized evidence, never a raw HTTP response or a personal-data payload. */
final readonly class ProviderResult
{
    public function __construct(
        public DocumentState $state,
        public ?string $officialCode = null,
        public ?string $accessKey = null,
        public ?string $protocol = null,
        public ?string $xml = null,
        public ?string $authorizedAt = null,
    ) {
        // Signature, issuer/environment/reference and XML correspondence still
        // MUST be verified by the adapter before persisting authorization.
        if ($state === DocumentState::Authorized && (
            $officialCode !== '100' || ! preg_match('/^[0-9A-Z]{44}$/D', $accessKey ?? '') || ! $protocol || ! $xml || ! $authorizedAt
        )) {
            throw new InvalidArgumentException('Autorização exige evidência fiscal oficial; HTTP 200 não é autorização.');
        }
        if ($state === DocumentState::Cancelled && (! in_array($officialCode, ['135', '155'], true) || ! preg_match('/^[0-9]{44}$/D', $accessKey ?? '') || ! $protocol || ! $xml)) {
            throw new InvalidArgumentException('Cancelamento exige evento fiscal oficial correlacionado.');
        }
    }
}
