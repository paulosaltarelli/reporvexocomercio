<?php

namespace Tests\Support;

use App\Platform\SmtpConnectionTest;
use App\Platform\SmtpSettings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

trait PlatformSmtpCases
{
    private function smtpVersion(): string
    {
        $response = $this->get('/vexo-admin/settings')->assertOk();
        $page = new \DOMDocument();
        @$page->loadHTML($response->getContent());
        $input = (new \DOMXPath($page))->query('//input[@name="version"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $input);
        return $input->getAttribute('value');
    }

    public function test_central_smtp_requires_a_central_session_and_checks_csrf_before_connection_or_writes(): void
    {
        $directory = sys_get_temp_dir().'/vendra-test-smtp-'.Str::uuid();
        $path = $directory.'/settings.enc';
        config(['platform.smtp_settings_path' => $path]);
        $probe = $this->mock(SmtpConnectionTest::class);
        $probe->shouldNotReceive('check');
        try {
            $this->withSession(['tenant_id' => $this->company->id, 'user_id' => 1, 'auth_version' => 0])
                ->get('/vexo-admin/settings')->assertRedirect('/vexo-admin/login');
            foreach (['/vexo-admin/settings/smtp', '/vexo-admin/settings/smtp/test'] as $uri) {
                $this->post($uri)->assertRedirect('/vexo-admin/login');
            }
            $this->centralSession();
            $environment = $this->app->environment();
            try {
                $this->app->instance('env', 'local');
                foreach (['/vexo-admin/settings/smtp', '/vexo-admin/settings/smtp/test'] as $uri) {
                    $this->post($uri)->assertStatus(419);
                }
            } finally {
                $this->app->instance('env', $environment);
            }
            $this->assertFileDoesNotExist($path);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_central_smtp_previews_updates_and_retains_its_private_password_only_for_the_same_endpoint(): void
    {
        $directory = sys_get_temp_dir().'/vendra-test-smtp-'.Str::uuid();
        $path = $directory.'/settings.enc';
        mkdir($directory, 0700);
        config(['platform.smtp_settings_path' => $path]);
        $probe = $this->mock(SmtpConnectionTest::class);
        $seen = [];
        $probe->shouldReceive('check')->andReturnUsing(function (array $settings) use (&$seen) {
            $seen[] = $settings;
        });
        $this->centralSession();
        try {
            $adminsUrl = route('platform.admins');
            $this->get('/vexo-admin')->assertOk()->assertDontSee('href="'.$adminsUrl.'"', false);
            $this->get('/vexo-admin/settings')->assertOk()->assertSee('href="'.$adminsUrl.'"', false);
            $this->get('/vexo-admin/admins')->assertOk()->assertSee('Configurações');
            $secret = 'smtp-synthetic-only-'.Str::random(24);
            $fields = ['host' => 'smtp.example.test', 'port' => 587, 'security' => 'starttls',
                'username' => 'mailer@example.test', 'password' => $secret, 'from_name' => 'Vendra QA',
                'from_address' => 'mailer@example.test', 'version' => $this->smtpVersion()];
            $url = '/vexo-admin/settings/smtp';
            $this->post($url.'/test', $fields)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertFileDoesNotExist($path);
            $this->post($url, $fields)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertFileExists($path);
            $stored = file_get_contents($path);
            $this->assertStringNotContainsString($secret, $stored);
            $this->assertSame(0, fileperms($path) & 0077);
            $environmentMail = config('mail');
            app(SmtpSettings::class)->send(function () use ($fields, $secret) {
                $this->assertSame('smtp', config('mail.default'));
                $this->assertSame($fields['host'], config('mail.mailers.smtp.host'));
                $this->assertSame($secret, config('mail.mailers.smtp.password'));
                $this->assertSame($fields['from_address'], config('mail.from.address'));
            });
            $this->assertSame($environmentMail, config('mail'));
            $this->get('/vexo-admin/settings')->assertOk()->assertSee('smtp.example.test')->assertDontSee($secret);
            $this->assertStringNotContainsString($secret, serialize(session()->all()));
            $fields['version'] = $this->smtpVersion();
            $fields['password'] = '';
            $this->post($url.'/test', $fields)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertCount(3, $seen);
            foreach ($seen as $request) {
                $this->assertSame($secret, $request['password']);
                $this->assertSame('smtp.example.test', $request['host']);
            }
            foreach (['host' => 'smtp-outra.example.test', 'port' => 465, 'security' => 'tls', 'username' => 'outra@example.test'] as $key => $value) {
                $this->post($url, array_replace($fields, [$key => $value]))->assertSessionHasErrors();
            }
            $this->assertCount(3, $seen);
            $this->assertSame($stored, file_get_contents($path));
            $bad = array_replace($fields, ['from_address' => 'e-mail inválido', 'password' => $secret]);
            $this->post($url, $bad)->assertSessionHasErrors('from_address');
            $this->assertStringNotContainsString($secret, serialize(session()->all()));
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
