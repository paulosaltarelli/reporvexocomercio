<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

final class InstallerInvitationMail extends Mailable
{
    public const SUBJECT = 'Seu convite para implantar uma loja no Vendra';

    public function __construct(
        public readonly string $companyName,
        public readonly string $installerName,
        #[\SensitiveParameter] public readonly string $activationUrl,
    ) {}

    public function build(): self
    {
        return $this->subject(self::SUBJECT)
            ->view('mail.transactional', ['kind' => 'installer_invitation', 'recipientName' => $this->installerName])
            ->text('mail.installer-invitation');
    }
}
