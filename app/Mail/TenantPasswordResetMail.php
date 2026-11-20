<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

final class TenantPasswordResetMail extends Mailable
{
    public const SUBJECT = 'Redefina sua senha no Vendra';

    public function __construct(
        public readonly string $companyName,
        #[\SensitiveParameter] public readonly string $resetUrl,
    ) {}

    public function build(): self
    {
        return $this->subject(self::SUBJECT)
            ->view('mail.transactional', ['kind'=>'password_reset', 'activationUrl'=>$this->resetUrl])
            ->text('mail.password-reset');
    }
}
