<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Platform\SupportAccess;
use App\Services\InstallerAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function form()
    {
        return view('auth.login');
    }

    public function login(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        $email = mb_strtolower(trim($data['email']));
        $key = 'login:'.hash('sha256', $r->ip().'|'.$r->attributes->get('tenant')->id.'|'.$email);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['email' => 'Muitas tentativas. Aguarde um minuto.']);
        }
        $user = User::where('email', $email)->first();
        $authenticated = $user && ! SupportAccess::isManaged($user)
            && mb_strtolower($user->email) === $email && Hash::check($data['password'], $user->password);
        $installer = $authenticated && $user->role === User::ROLE_INSTALLER;
        if ($installer) {
            if (!$user->active) InstallerAccess::restoreAfterCompletion($user);
            $authenticated = InstallerAccess::applyGrant($user);
        }
        if (! $authenticated || ! $user->active) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['email' => 'E-mail ou senha inválidos.']);
        }
        RateLimiter::clear($key);
        if ($installer) {
            $r->session()->invalidate();
            $r->session()->regenerateToken();
        } else {
            $r->session()->regenerate();
        }
        $r->session()->forget('platform_support');
        $r->session()->put(['tenant_id' => $r->attributes->get('tenant')->id, 'user_id' => $user->id, 'auth_version' => $user->auth_version]);

        $start=$user->role==='admin'?'dashboard':app(\App\Access\AccessProfiles::class)->landing($user);
        if ($user->role==='admin' && app(\App\Services\CompanyOnboarding::class)->required($r->attributes->get('tenant'))) $start='setup';
        return redirect()->route($start, ['company' => $r->route('company')]);
    }

    public function logout(Request $r)
    {
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login', ['company' => $r->route('company')]);
    }
}
