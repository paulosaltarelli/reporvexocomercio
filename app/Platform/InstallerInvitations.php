<?php

namespace App\Platform;

use App\Mail\InstallerInvitationMail;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InstallerAccess;
use App\Services\TenantConnection;
use App\Tenancy\CompanyHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

final class InstallerInvitations
{
    public function __construct(private TenantConnection $connection) {}

    public function storeState(Tenant $company, \Illuminate\Support\Collection $invitations): array
    {
        if (!$company->active) return ['open'=>false, 'activeUsers'=>collect()];
        try {
            $this->connection->connect($company);
            $users = User::whereIn('id',$invitations->pluck('user_id')->filter())->where('role',User::ROLE_INSTALLER)
                ->get(['id','email','role','active','auth_version','updated_at'])->keyBy('id');
            $activeUsers = $users->where('active',true)->pluck('email','id');
            $open = InstallerAccess::open();
            if (!$open) {
                foreach ($invitations as $invitation) {
                    $user = $users->get($invitation->user_id);
                    if ($user && !$user->active && InstallerAccess::disabledByCompletion($user,$invitation)) {
                        $activeUsers->put($user->id,$user->email);
                    }
                }
            }
            return compact('open','activeUsers');
        } finally {
            $this->connection->disconnect();
        }
    }

    public function change(Request $request, int $companyId, ?int $invitationId, string $operation): InstallerInvitation
    {
        $input = $request->validate($operation === 'create' ? [
            'name'=>'required|string|max:180','email'=>'required|email|max:180','access_profile'=>'sometimes|required|in:installer,tenant_dba','mfa_code'=>PlatformMfa::required() ? 'nullable|string|max:32' : 'exclude',
        ] : ['expected_version'=>'required|integer|min:1','mfa_code'=>PlatformMfa::required() ? 'nullable|string|max:32' : 'exclude']);
        if ($operation === 'update') {
            $input += $request->validate(['enabled'=>'required|boolean','access_profile'=>'required|in:installer,tenant_dba']);
        }
        $token = in_array($operation, ['create','resend'], true) ? bin2hex(random_bytes(32)) : null;
        $challenge = PlatformMfa::needsChallenge((int)$request->session()->get('platform_mfa_at'));

        [$invitation,$company,$attempt] = DB::connection('central')->transaction(function () use ($request,$companyId,$invitationId,$operation,$input,$token,$challenge) {
            $admin=PlatformAdmin::lockForUpdate()->find($request->session()->get('platform_admin_id'));
            abort_unless($admin && $admin->active && $admin->auth_version === (int)$request->session()->get('platform_auth_version',-1),403);
            if ($challenge && !PlatformMfa::consume($admin,$input['mfa_code']??'')) {
                throw ValidationException::withMessages(['mfa_code'=>'Confirme um código atual do autenticador ou de recuperação.']);
            }
            $company=Tenant::lockForUpdate()->findOrFail($companyId);
            $invitation=$invitationId === null ? new InstallerInvitation : InstallerInvitation::where('tenant_id',$companyId)->lockForUpdate()->findOrFail($invitationId);
            if ($invitation->exists && (int)$input['expected_version'] !== $invitation->version) {
                throw ValidationException::withMessages(['expected_version'=>'O acesso mudou. Confira a situação atual antes de continuar.']);
            }
            $attempt = null;
            $before = ['access_profile'=>$invitation->access_profile, 'status'=>$invitation->status];
            if ($operation === 'update') {
                $this->updateGrant($company,$invitation,(bool)$input['enabled'],$input['access_profile']);
            } elseif ($operation === 'revoke') {
                if ($invitation->status === 'revoked') return [$invitation,$company,$attempt];
                $this->revoke($company,$invitation);
            } else {
                abort_unless($company->active,409,'A empresa está suspensa.');
                $email=$invitation->exists ? $invitation->email : mb_strtolower($input['email']);
                if ($invitation->exists && $invitation->status !== 'pending') {
                    throw ValidationException::withMessages(['email'=>'Somente convites pendentes podem ser reenviados.']);
                }
                if (!$invitation->exists && InstallerInvitation::where('tenant_id',$companyId)->where('email',$email)->whereIn('status',['pending','accepted'])->exists()) {
                    throw ValidationException::withMessages(['email'=>'Já existe um convite ou acesso para este e-mail. Consulte a lista abaixo.']);
                }
                $this->checkRecipient($company,$email,$invitation);
                if (!$invitation->exists) $invitation->fill(['tenant_id'=>$companyId,'name'=>$input['name'],'email'=>$email,'issued_by'=>$admin->id,'access_profile'=>$input['access_profile']??'installer']);
                $invitation->fill(['status'=>'pending','delivery_status'=>'sending','delivered_at'=>null,'token_digest'=>hash('sha256',$token),
                    'expires_at'=>now()->addDay(),'version'=>$invitation->exists ? $invitation->version+1 : 1])->save();
                $attempt = MailAttempt::begin($companyId, $company->name, $invitation->email,
                    InstallerInvitationMail::SUBJECT, 'installer_invitation', $invitation->id);
            }
            PlatformAudit::create(['platform_admin_id'=>$admin->id,'actor_name'=>$admin->name,'tenant_id'=>$companyId,
                'action'=>'installer.'.$operation,'changes'=>['invitation_id'=>$invitation->id,'email'=>$invitation->email,'version'=>$invitation->version,
                    'before'=>$before,'after'=>['access_profile'=>$invitation->access_profile,'status'=>$invitation->status]]]);
            return [$invitation,$company,$attempt];
        });
        if ($challenge) $request->session()->put('platform_mfa_at',time());
        if ($token !== null) $this->deliver($company,$invitation,$token,$attempt);
        return $invitation->fresh();
    }

    private function checkRecipient(Tenant $company,string $email,InstallerInvitation $invitation): void
    {
        if (str_ends_with($email,'@support.vexo.invalid')) throw ValidationException::withMessages(['email'=>'Informe um e-mail nominal do implantador.']);
        try {
            $this->connection->connect($company);
            DB::connection('tenant')->transaction(function () use ($company,$email,$invitation) {
                InstallerAccess::lockSetup();
                $existing=User::where('email',$email)->lockForUpdate()->first();
                if (!$existing) return;
                $recovering=$invitation->exists && InstallerAccess::boundToInvite($existing,(int)$invitation->id);
                if ($existing->role!==User::ROLE_INSTALLER || InstallerAccess::granted($existing)
                    || (!$recovering && !InstallerAccess::boundToRevokedInvite($existing,$company->id))) {
                    throw ValidationException::withMessages(['email'=>'Este e-mail já pertence a uma conta da empresa. Use outro e-mail.']);
                }
            });
        } finally {
            $this->connection->disconnect();
        }
    }

    /** The caller locks company and invitation, in that order, and audits the actor in the same central transaction. */
    public function updateGrant(Tenant $company, InstallerInvitation $invitation, bool $enabled, string $profile): void
    {
        if (!DB::connection('central')->transactionLevel()) throw new \LogicException('A concessão exige transação central.');
        abort_unless((int)$invitation->tenant_id === (int)$company->id, 404);
        if (!$invitation->accepted_at || !$invitation->user_id || !in_array($invitation->status,['accepted','revoked'],true)
            || !array_key_exists($profile,InstallerInvitation::PROFILES)) {
            throw ValidationException::withMessages(['grant'=>'Selecione um acesso aceito e um perfil válido.']);
        }
        if ($enabled) {
            abort_unless($company->active,409,'A empresa está suspensa.');
            $another = InstallerInvitation::where('tenant_id',$company->id)->where('email',$invitation->email)
                ->where('id','<>',$invitation->id)->where(function ($query) use ($invitation) {
                    $query->whereIn('status',['accepted','pending'])->orWhere('id','>',$invitation->id);
                })->exists();
            if ($another) throw ValidationException::withMessages(['grant'=>'Há outro vínculo para este e-mail. Consulte o registro mais recente.']);
            try {
                $this->connection->connect($company);
                DB::connection('tenant')->transaction(function () use ($invitation) {
                    InstallerAccess::lockSetup();
                    $user = User::whereKey($invitation->user_id)->where('role',User::ROLE_INSTALLER)
                        ->where('email',$invitation->email)->lockForUpdate()->first(['id','active','auth_version','role','email']);
                    if (!$user) throw ValidationException::withMessages(['grant'=>'A conta vinculada não corresponde a este acesso.']);
                    $user->update(['active'=>true,'auth_version'=>$user->auth_version+1]);
                });
            } finally {
                $this->connection->disconnect();
            }
            $invitation->update(['access_profile'=>$profile,'status'=>'accepted','revoked_at'=>null,'version'=>$invitation->version+1]);
        } elseif ($invitation->status === 'accepted') {
            $invitation->access_profile = $profile;
            $this->revoke($company,$invitation);
        } else {
            $invitation->update(['access_profile'=>$profile,'version'=>$invitation->version+1]);
        }
    }

    private function revoke(Tenant $company,InstallerInvitation $invitation): void
    {
        // The central grant is checked on each installer request, including after a store is reactivated.
        if ($company->active && $invitation->user_id) {
            try {
                $this->connection->connect($company);
                DB::connection('tenant')->transaction(function () use ($invitation) {
                    InstallerAccess::lockSetup();
                    User::whereKey($invitation->user_id)->where('role',User::ROLE_INSTALLER)->where('email',$invitation->email)
                        ->update(['active'=>false,'auth_version'=>DB::raw('auth_version + 1')]);
                });
            } finally {
                $this->connection->disconnect();
            }
        }
        $invitation->update(['status'=>'revoked','revoked_at'=>now(),'token_digest'=>null,'version'=>$invitation->version+1]);
    }

    private function deliver(Tenant $company,InstallerInvitation $invitation, #[\SensitiveParameter] string $token, MailAttempt $attempt): void
    {
        $sent=false;
        try {
            $url=CompanyHosts::url($company->slug,'installer.invite').'#token='.$token;
            app(SmtpSettings::class)->send(fn()=>Mail::to($invitation->email)->send(new InstallerInvitationMail($company->name,$invitation->name,$url)));
            $sent=true;
        } catch (\Throwable) {
            // Transport diagnostics can include the message body. Store only the delivery result.
        }
        DB::connection('central')->transaction(function () use ($attempt, $invitation, $token, $sent) {
            $attempt->finish($sent);
            InstallerInvitation::whereKey($invitation->id)->where('version',$invitation->version)->where('status','pending')
                ->where('token_digest',hash('sha256',$token))->update($sent
                    ? ['delivery_status'=>'sent','delivered_at'=>now()]
                    : ['delivery_status'=>'failed','token_digest'=>null]);
        });
    }
}
