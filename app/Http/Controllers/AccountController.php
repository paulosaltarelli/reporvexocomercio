<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function password(Request $r)
    {
        return view('account.password');
    }

    public function changePassword(Request $r, string $company)
    {
        $d = $r->validate(['current_password' => 'required|string|max:128', 'password' => 'required|string|min:12|max:128|confirmed|different:current_password']);
        $user = $r->attributes->get('currentUser');
        $key = 'password:'.$r->attributes->get('tenant')->id.':'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['current_password' => 'Muitas tentativas. Aguarde um minuto.']);
        }
        if (! Hash::check($d['current_password'], $user->password)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['current_password' => 'A senha atual não confere.']);
        }
        DB::connection('tenant')->transaction(function () use ($user, $d) {
            $locked = User::lockForUpdate()->findOrFail($user->id);
            // Recheck under lock: concurrent password changes cannot reuse the old password.
            if (! Hash::check($d['current_password'], $locked->password)) {
                throw ValidationException::withMessages(['current_password' => 'A senha foi alterada. Entre novamente.']);
            }
            $locked->update(['password' => $d['password'], 'must_change_password' => false, 'auth_version' => $locked->auth_version + 1, 'password_changed_at' => now()]);
            $user->auth_version = $locked->auth_version;
        });
        RateLimiter::clear($key);
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        $r->session()->put(['tenant_id' => $r->attributes->get('tenant')->id, 'user_id' => $user->id, 'auth_version' => $user->auth_version]);

        return redirect()->route($user->role==='admin'?'dashboard':'desk', ['company' => $company])->with('success', 'Senha alterada. Outras sessões deste usuário foram encerradas.');
    }

    public function updateUser(Request $r, string $company, int $id)
    {
        abort_unless($r->attributes->get('currentUser')->role === 'admin', 403);
        $user = User::findOrFail($id);
        $d = $r->validate(['name' => 'required|string|max:180', 'email' => ['required', 'email', 'max:180', Rule::unique('tenant.users', 'email')->ignore($id)], 'role' => 'required|in:admin,operator', 'active' => 'required|boolean', 'password' => 'nullable|string|min:12|max:128|confirmed']);
        DB::connection('tenant')->transaction(function () use ($d, $id) {
            // Lock in stable order to protect the last active administrator.
            $users = User::orderBy('id')->lockForUpdate()->get();
            $u = $users->firstWhere('id', $id);
            abort_unless($u, 404);
            $isLastAdmin = $u->active && $u->role === 'admin' && $users->where('active', true)->where('role', 'admin')->count() === 1;
            if ($isLastAdmin && (! $d['active'] || $d['role'] !== 'admin')) {
                throw ValidationException::withMessages(['role' => 'Mantenha pelo menos um administrador ativo.']);
            }
            $changes = ['name' => $d['name'], 'email' => $d['email'], 'role' => $d['role'], 'active' => (bool) $d['active']];
            $securityChanged = $u->email !== $d['email'] || $u->role !== $d['role'] || $u->active !== (bool) $d['active'];
            if (! empty($d['password'])) {
                $changes['password'] = $d['password'];
                $changes['must_change_password'] = true;
                $securityChanged = true;
            }
            if ($securityChanged) {
                $changes['auth_version'] = $u->auth_version + 1;
            }
            $u->update($changes);
        }, 3);

        return back()->with('success', 'Usuário atualizado. Alterações de acesso encerram suas sessões anteriores.');
    }
}
