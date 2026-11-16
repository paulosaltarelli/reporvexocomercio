<?php

namespace App\Platform\Commands;

use App\Platform\PlatformAdmin;
use App\Platform\PlatformAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class PlatformAdminCommand extends Command
{
    protected $signature = 'platform:admin {email} {--name=} {--reset-password : Rotacionar senha de um administrador central existente}';
    protected $description = 'Cria administrador central ou rotaciona sua senha por prompt oculto; não recebe senha em argumento.';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Execute em terminal interativo. Senhas não são aceitas por argumentos, variáveis ou saída de comandos.');
            return self::FAILURE;
        }
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $existing = PlatformAdmin::where('email', $email)->first();
        if ($existing && ! $this->option('reset-password')) {
            $this->error('Administrador já existe. Use --reset-password para rotacionar sua senha e invalidar sessões.');
            return self::FAILURE;
        }
        $name = $this->option('name') ?: $existing?->name ?: $this->ask('Nome do administrador central');
        $password = $this->secret('Nova senha central (14 a 72 caracteres, letras e números)', false);
        $confirmation = $this->secret('Confirme a nova senha', false);
        $validator = Validator::make(['email' => $email, 'name' => $name, 'password' => $password, 'password_confirmation' => $confirmation], ['email' => 'required|email|max:180', 'name' => 'required|string|min:2|max:180', 'password' => ['required', 'string', 'min:14', 'max:72', 'confirmed', 'regex:/[a-zA-Z]/', 'regex:/[0-9]/']]);
        if ($validator->fails()) {
            $this->error('Nome, e-mail ou senha inválidos. Confira o e-mail e use senha igual nos dois prompts, com 14 a 72 caracteres, letras e números.');
            return self::FAILURE;
        }
        $hash = Hash::make($password);
        unset($password, $confirmation, $validator);
        DB::connection('central')->transaction(function () use ($email, $name, $hash) {
            $admin = PlatformAdmin::where('email', $email)->lockForUpdate()->first();
            if ($admin && ! $this->option('reset-password')) {
                throw new \RuntimeException('Administrador criado por outra operação.');
            }
            $creating = ! $admin;
            $admin ??= new PlatformAdmin(['email' => $email, 'active' => true, 'auth_version' => 0]);
            $admin->name = $name;
            $admin->password = $hash;
            $admin->auth_version++;
            $admin->save();
            PlatformAudit::create(['platform_admin_id' => $admin->id, 'actor_name' => 'Console administrativo', 'action' => $creating ? 'platform.admin_created' : 'platform.password_rotated']);
        });
        $this->info('Administrador central salvo. A senha não será exibida; sessões anteriores foram invalidadas.');
        return self::SUCCESS;
    }
}
