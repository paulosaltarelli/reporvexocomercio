<?php

namespace App\Platform;

use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class SmtpConnectionTest
{
    public function check(#[\SensitiveParameter] array $settings): void
    {
        try {
            $transport = new class($settings['host'], $settings['port'], $settings['security']==='tls') extends EsmtpTransport {
                public function supportsAuthentication(): bool
                {
                    return isset($this->getCapabilities()['AUTH']);
                }
            };
            $transport->getStream()->setTimeout(8);
            $transport->setRequireTls(true);
            $transport->setUsername($settings['username']);
            $transport->setPassword($settings['password']);
            try {
                $transport->start();
                if (!$transport->supportsAuthentication()) throw new \RuntimeException('smtp_auth_unavailable');
            } finally {
                $transport->stop();
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['connection'=>'A conexão SMTP não foi confirmada. Confira servidor, porta, TLS, usuário e senha.']);
        }
    }
}
