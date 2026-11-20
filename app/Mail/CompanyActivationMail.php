<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

final class CompanyActivationMail extends Mailable
{
    public const SUBJECT = 'Ative sua conta de administrador no Vendra';

    public function __construct(
        public readonly string $companyName,
        public readonly string $recipientName,
        public readonly string $activationUrl,
        #[\SensitiveParameter] public readonly string $activationCode,
    ) {}

    public function build(): self
    {
        return $this->subject(self::SUBJECT)
            ->view('mail.transactional', ['kind' => 'company_activation'])
            ->text('mail.company-activation');
    }
}
