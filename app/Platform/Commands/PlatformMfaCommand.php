<?php

namespace App\Platform\Commands;

use App\Platform\PlatformAdmin;
use App\Platform\PlatformAudit;
use App\Platform\Totp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PlatformMfaCommand extends Command
{
    protected $signature = 'platform:mfa {email} {--replace : Substituir o fator após verificar a identidade do titular}';
    protected $description = 'Cadastra um autenticador e gera códigos de recuperação em um terminal administrativo privado.';

    public function handle(): int
    {
        if (!$this->input->isInteractive() || !function_exists('posix_geteuid') || posix_geteuid() !== 0 || !stream_isatty(STDIN) || !stream_isatty(STDOUT)) {
            $this->error('Utilize um terminal root privado e interativo.');
            return self::FAILURE;
        }
        $admin = PlatformAdmin::where('active', true)->where('email', mb_strtolower(trim($this->argument('email'))))->firstOrFail();
        if ($admin->mfa_secret && !$this->option('replace')) {
            $this->error('O titular já possui autenticador. A recuperação administrativa exige --replace.');
            return self::FAILURE;
        }
        $secret = Totp::newSecret();
        $this->line('Cadastre no autenticador como Vexo · '.$admin->email.' (TOTP SHA1, 6 dígitos, 30 segundos).');
        $this->line('Chave manual: '.$secret);
        $input = $this->secret('Código atual do autenticador', false);
        $step = Totp::matchStep($secret, (string)$input, time(), null);
        if ($step === null) {
            $this->error('Código inválido. O cadastro anterior foi preservado.');
            return self::FAILURE;
        }
        $codes = [];
        for ($index = 0; $index < 8; $index++) {
            $codes[] = bin2hex(random_bytes(16));
        }
        DB::connection('central')->transaction(function () use ($admin, $secret, $step, $codes) {
            $locked = PlatformAdmin::whereKey($admin->id)->where('active', true)->lockForUpdate()->firstOrFail();
            if ($locked->auth_version !== $admin->auth_version) {
                throw new \RuntimeException('A conta mudou durante o cadastro. Reinicie a operação.');
            }
            $locked->mfa_secret = $secret;
            $locked->mfa_last_step = $step;
            $locked->mfa_recovery = array_map(fn ($code) => hash('sha256', $code), $codes);
            $locked->auth_version++;
            $locked->save();
            PlatformAudit::create(['platform_admin_id'=>$locked->id, 'actor_name'=>'Console administrativo', 'action'=>'platform.mfa_enrolled']);
        });
        $this->info('Guarde estes códigos individuais de uso único em local protegido. Para entrar, aguarde o próximo código do autenticador.');
        foreach ($codes as $code) {
            $this->line($code);
        }
        return self::SUCCESS;
    }
}
