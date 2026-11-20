<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

final class InstallerInvitationMail extends Mailable
{
    public function __construct(
        public readonly string $companyName,
        public readonly string $installerName,
        #[\SensitiveParameter] public readonly string $activationUrl,
    ) {}

    public function build(): self
    {
        return $this->subject('Convite para implantar '.$this->companyName.' no Vendra')
            ->text('mail.installer-invitation');
    }
}
