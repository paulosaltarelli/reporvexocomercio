<?php

namespace App\Http\Controllers;

use App\Services\AcceptInstallerInvitation;
use Illuminate\Http\Request;

final class InstallerInvitationController extends Controller
{
    public function form()
    {
        return response()->view('auth.installer-invitation')->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function accept(Request $request, AcceptInstallerInvitation $service)
    {
        $input = $request->validate(['email' => 'required|email|max:180', 'token' => ['required', 'string', 'size:64'],
            'password' => 'required|string|min:12|max:72|confirmed']);
        $tenant = $request->attributes->get('tenant');
        $user = $service->accept($tenant, $input['email'], $input['token'], $input['password']);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'auth_version' => $user->auth_version]);

        return redirect()->route('setup', ['company' => $tenant->slug])->header('Cache-Control', 'no-store');
    }
}
