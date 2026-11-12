<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantConnection;
use Illuminate\Console\Command;

class SetUserPassword extends Command
{
    protected $signature = 'vendra:password {company} {email}';

    protected $description = 'Define senha de um usuário por entrada oculta no terminal.';

    public function handle(TenantConnection $connection): int
    {
        $tenant = Tenant::where('slug', $this->argument('company'))->firstOrFail();
        try {
            $connection->connect($tenant);
            $user = User::where('email', $this->argument('email'))->firstOrFail();
            $password = $this->secret('Nova senha (mínimo 12 caracteres)');
            if (! is_string($password) || strlen($password) < 12) {
                $this->error('Senha muito curta.');

                return self::FAILURE;
            }
            if ($password !== $this->secret('Repita a senha')) {
                $this->error('Senhas diferentes.');

                return self::FAILURE;
            }
            $user->update(['password' => $password, 'must_change_password' => false, 'auth_version' => $user->auth_version + 1, 'password_changed_at' => now()]);
            $this->info('Senha alterada.');

            return self::SUCCESS;
        } finally {
            $connection->disconnect();
        }
    }
}
