<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Platform\PlatformAdmin;
use App\Platform\PlatformAudit;
use App\Platform\PlatformMfa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function form()
    {
        return view('platform.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:180', 'password' => 'required|string|max:72', 'mfa_code' => 'nullable|string|max:32']);
        $email = mb_strtolower(trim($data['email']));
        $key = 'platform-login:'.hash('sha256', $email.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Muitas tentativas. Aguarde um minuto para tentar novamente.']);
        }
        RateLimiter::hit($key, 60);
        $admin = PlatformAdmin::where('email', $email)->first();
        // Always verify a hash even for unknown identities, avoiding a cheap enumeration path.
        $valid = Hash::check($data['password'], $admin?->password ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if (! $admin || ! $admin->active || ! $valid) {
            throw ValidationException::withMessages(['email' => 'Acesso central inválido ou indisponível.']);
        }
        DB::connection('central')->transaction(function () use ($admin, $data) {
            $locked = PlatformAdmin::whereKey($admin->id)->lockForUpdate()->first();
            if (!$locked || !$locked->active || $locked->auth_version !== $admin->auth_version ||
                !PlatformMfa::consume($locked, $data['mfa_code'] ?? '')) {
                throw ValidationException::withMessages(['email' => 'Acesso central inválido ou indisponível.']);
            }
            PlatformAudit::create(['platform_admin_id' => $admin->id, 'action' => 'platform.login', 'actor_name' => $admin->name]);
        });
        RateLimiter::clear($key);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put(['platform_admin_id' => $admin->id, 'platform_auth_version' => $admin->auth_version, 'platform_mfa_at' => time()]);
        return redirect()->route('platform.index');
    }

    public function logout(Request $request)
    {
        $admin = $request->attributes->get('platformAdmin');
        PlatformAudit::create(['platform_admin_id' => $admin->id, 'action' => 'platform.logout', 'actor_name' => $admin->name]);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('platform.login');
    }
}
