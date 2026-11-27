<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    public const ROLE_INSTALLER = 'installer';

    private bool $tenantDba = false;

    /** The central grant applies only to this in-memory user on the resolved tenant. */
    public function applyTenantDba(): void
    {
        if ($this->getRawOriginal('role') !== self::ROLE_INSTALLER) {
            throw new \LogicException('Perfil de implantação inválido.');
        }
        $this->tenantDba = true;
    }

    public function getRoleAttribute(?string $value): ?string
    {
        return $this->tenantDba ? 'admin' : $value;
    }

    protected $connection = 'tenant';

    protected $guarded = ['id'];

    protected $hidden = ['password'];

    public function canEnrollFiscalCertificate(): bool
    {
        return $this->getRawOriginal('role') === 'admin' && $this->allows('fiscal.manage');
    }

    public function allows(string $permission): bool
    {
        return app(\App\Access\AccessProfiles::class)->allows($this, $permission);
    }

    public function seller()
    {
        return $this->hasOne(Seller::class);
    }

    protected function casts(): array
    {
        return ['password' => 'hashed', 'active' => 'boolean', 'must_change_password' => 'boolean', 'auth_version' => 'integer', 'password_changed_at' => 'datetime'];
    }
}
