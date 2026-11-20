<?php

namespace App\Http\Controllers;

use App\Platform\CompanyActivation;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class CompanyActivationController extends Controller
{
    public function activate(Request $request, CompanyActivation $service)
    {
        $data = $request->validate([
            'email' => 'required|email|max:180',
            'activation_code' => 'required|string|size:32',
            'password' => 'required|string|min:12|max:72|confirmed',
        ]);
        if (strlen($data['password']) > 72) throw ValidationException::withMessages(['password' => 'Use até 72 bytes para a senha.']);
        $service->activate($request->attributes->get('tenant'), $data['email'], trim($data['activation_code']), $data['password']);
        return redirect()->route('login', ['company' => $request->route('company')])->with('success', 'Conta ativada. Entre com o e-mail e a senha que você definiu.');
    }
}
