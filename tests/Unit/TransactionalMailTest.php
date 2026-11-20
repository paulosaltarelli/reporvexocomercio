<?php

namespace Tests\Unit;

use App\Mail\CompanyActivationMail;
use App\Mail\InstallerInvitationMail;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class TransactionalMailTest extends TestCase
{
    public function test_each_transactional_message_delivers_html_plain_text_and_an_embedded_logo(): void
    {
        $company = 'Loja QA & Filhos';
        $installerToken = bin2hex(random_bytes(32));
        $activationCode = bin2hex(random_bytes(16));
        $inviteUrl = 'https://a.example.test/app/a/convite-implantacao#token='.$installerToken;
        $activateUrl = 'https://a.example.test/app/a/ativar';
        $cases = [
            [new InstallerInvitationMail($company, 'Pessoa QA', $inviteUrl), $inviteUrl, $installerToken],
            [new CompanyActivationMail($company, 'Pessoa QA', $activateUrl, $activationCode), $activateUrl, $activationCode],
        ];
        foreach ($cases as [$mail, $url, $credential]) {
            $sent = Mail::mailer('array')->to('recipient@example.test')->send($mail);
            $message = $sent->getOriginalMessage();
            $this->assertNotEmpty($message->getSubject());
            $this->assertSame('recipient@example.test', $message->getTo()[0]->getAddress());
            $this->assertStringContainsString('cid:', $message->getHtmlBody());
            $this->assertStringContainsString('multipart/alternative', $message->toString());
            $this->assertNotEmpty($message->getAttachments());
            $this->assertSame('image', $message->getAttachments()[0]->getMediaType());
            foreach ([html_entity_decode($message->getHtmlBody()), $message->getTextBody()] as $part) {
                $this->assertStringContainsString($company, $part);
                $this->assertStringContainsString($url, $part);
                $this->assertStringContainsString($credential, $part);
            }
        }
    }
}
