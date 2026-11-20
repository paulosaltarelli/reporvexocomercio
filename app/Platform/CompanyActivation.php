<?php

namespace App\Platform;

use App\Mail\CompanyActivationMail;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CompanyHosts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

final class CompanyActivation
{
    public function send(int $id): void
    {
        // Only a real delivery transport may receive the one-time credential.
        if (!app(SmtpSettings::class)->available()) {
            throw new \RuntimeException('activation_smtp_required');
        }
        $code = bin2hex(random_bytes(16));
        $delivery = DB::connection('central')->transaction(function () use ($id, $code) {
            $job = ProvisionRequest::lockForUpdate()->findOrFail($id);
            if ($job->status !== 'completed' || $job->activation_status !== 'queued' || $job->activation_retry_at?->isFuture()) return null;
            if (!Tenant::whereKey($job->tenant_id)->where('active', true)->exists()) return null;
            $job->update(['activation_digest' => hash('sha256', $code), 'activation_expires_at' => now()->addDay(), 'activation_retry_at' => now()->addMinutes(10)]);
            $attempt = MailAttempt::begin($job->tenant_id, $job->name, $job->admin_email,
                CompanyActivationMail::SUBJECT, 'company_activation', $job->id);
            return [$job, $attempt];
        });
        if (!$delivery) return;

        [$job, $attempt] = $delivery;
        try {
            $url = CompanyHosts::url($job->slug, 'company.activate');
            $message = new CompanyActivationMail($job->name, $job->admin_name, $url, $code);
            app(SmtpSettings::class)->send(fn () => Mail::to($job->admin_email)->send($message));
        } catch (\Throwable) {
            $attempt->finish(false);
            throw new \RuntimeException('activation_smtp_failed');
        }

        DB::connection('central')->transaction(function () use ($attempt, $id, $code) {
            $attempt->finish(true);
            ProvisionRequest::whereKey($id)->where('activation_status', 'queued')->where('activation_digest', hash('sha256', $code))
                ->update(['activation_status' => 'sent', 'activation_retry_at' => null]);
        });
    }

    public function activate(Tenant $tenant, string $email, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $password): void
    {
        DB::connection('central')->transaction(function () use ($tenant, $email, $code, $password) {
            $job = ProvisionRequest::where('tenant_id', $tenant->id)->where('status', 'completed')->lockForUpdate()->first();
            if (!$job || !in_array($job->activation_status, ['queued', 'sent'], true) || !$job->activation_expires_at?->isFuture()
                || strcasecmp($job->admin_email, $email) !== 0 || !hash_equals((string) $job->activation_digest, hash('sha256', $code))) {
                throw ValidationException::withMessages(['activation_code' => 'E-mail ou código inválido ou expirado.']);
            }
            DB::connection('tenant')->transaction(function () use ($email, $password) {
                $user = User::where('email', $email)->where('active', true)->where('role', 'admin')->lockForUpdate()->first();
                if (!$user || !$user->must_change_password || $user->auth_version !== 0 || SupportAccess::isManaged($user)) {
                    throw ValidationException::withMessages(['activation_code' => 'Esta conta já foi alterada. Entre com sua senha ou procure o administrador.']);
                }
                $user->update(['password' => $password, 'must_change_password' => false, 'auth_version' => 1, 'password_changed_at' => now()]);
            });
            $job->update(['activation_status' => 'used', 'activation_digest' => null, 'activation_expires_at' => null, 'activation_retry_at' => null]);
        });
    }
}
