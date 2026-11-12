<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Tenant users must be provisioned deliberately; never seed shared credentials.
        $this->command?->warn('Nenhum usuário padrão é criado. Use o provisionamento específico da empresa.');
    }
}
