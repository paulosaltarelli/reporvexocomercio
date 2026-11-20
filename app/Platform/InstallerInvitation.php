<?php

namespace App\Platform;

use Illuminate\Database\Eloquent\Model;

final class InstallerInvitation extends Model
{
    protected $connection = 'central';
    protected $table = 'platform_installer_invitations';
    protected $guarded = ['id'];
    protected $hidden = ['token_digest'];

    protected function casts(): array
    {
        return ['expires_at'=>'datetime','accepted_at'=>'datetime','revoked_at'=>'datetime','delivered_at'=>'datetime','version'=>'integer'];
    }

    public const PROFILES = ['installer'=>'Implantação', 'tenant_dba'=>'DBA da loja'];

    public function label(bool $activeCompany, bool $userActive): string
    {
        return match (true) {
            $this->status === 'revoked' => 'Revogado',
            !$activeCompany => 'Empresa suspensa',
            $this->status === 'accepted' => $userActive ? 'Acesso ativo' : 'Acesso desabilitado',
            $this->delivery_status === 'failed' => 'Falha no envio do e-mail',
            !$this->expires_at?->isFuture() => 'Convite expirado',
            $this->delivery_status === 'sending' => 'Envio pendente de confirmação',
            default => 'Enviado · aguardando aceite',
        };
    }
}
