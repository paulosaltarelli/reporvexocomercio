<?php

namespace App\Http\Controllers;

use App\Services\AcceptInstallerInvitation;
use Illuminate\Http\Request;

final class InstallerInvitationController extends Controller
{
    public function home(Request $request)
    {
        abort_unless($request->attributes->get('currentUser')?->role === \App\Models\User::ROLE_INSTALLER, 403);
        if (\App\Services\InstallerAccess::open()) {
            return redirect()->route('setup', ['company' => $request->route('company')]);
        }
        return view('auth.installer-home');
    }

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

        abort_unless(\App\Services\InstallerAccess::applyGrant($user), 403);
        $landing = $user->role === 'admin'
            ? (app(\App\Services\CompanyOnboarding::class)->required($tenant) ? 'setup' : 'dashboard')
            : app(\App\Access\AccessProfiles::class)->landing($user);
        return redirect()->route($landing, ['company' => $tenant->slug])->header('Cache-Control', 'no-store');
    }
}
