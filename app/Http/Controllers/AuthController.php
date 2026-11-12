<?php

namespace App\Http\Controllers;

use App\Models\User;
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
        $key = 'login:'.hash('sha256', $r->ip().'|'.$r->attributes->get('tenant')->id.'|'.strtolower($data['email']));
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['email' => 'Muitas tentativas. Aguarde um minuto.']);
        }
        $user = User::where('email', $data['email'])->where('active', true)->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['email' => 'E-mail ou senha inválidos.']);
        }
        RateLimiter::clear($key);
        $r->session()->regenerate();
        $r->session()->put(['tenant_id' => $r->attributes->get('tenant')->id, 'user_id' => $user->id, 'auth_version' => $user->auth_version]);

        return redirect()->route('dashboard', ['company' => $r->route('company')]);
    }

    public function logout(Request $r)
    {
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login', ['company' => $r->route('company')]);
    }
}
