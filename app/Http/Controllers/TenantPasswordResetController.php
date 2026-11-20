<?php

namespace App\Http\Controllers;

use App\Services\TenantPasswordReset;
use Illuminate\Http\Request;
use function Illuminate\Support\defer;

final class TenantPasswordResetController extends Controller
{
    public function forgot()
    {
        return response()->view('auth.forgot-password')->header('Cache-Control', 'no-store');
    }

    public function send(Request $request, TenantPasswordReset $service)
    {
        $input = $request->validate(['email' => 'required|email|max:180']);
        $email = mb_strtolower(trim($input['email']));
        $tenant = $request->attributes->get('tenant');
        defer(fn () => $service->request($tenant->id, $email));
        return redirect()->route('password.request', ['company' => $tenant->slug])
            ->with('success', 'Se houver uma conta habilitada para este e-mail, enviaremos as instruções para redefinir a senha.');
    }

    public function form()
    {
        return response()->view('auth.reset-password')->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function update(Request $request, TenantPasswordReset $service)
    {
        $input = $request->validate(['email' => 'required|email|max:180', 'token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'password' => 'required|string|min:12|max:72|confirmed']);
        $tenant = $request->attributes->get('tenant');
        $service->reset($tenant, mb_strtolower(trim($input['email'])), $input['token'], $input['password']);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login', ['company' => $tenant->slug])->with('success', 'Senha redefinida. Entre com a nova senha.');
    }
}
