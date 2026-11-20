<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Platform\PlatformAudit;
use App\Platform\SmtpConnectionTest;
use App\Platform\SmtpSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class SettingsController extends Controller
{
    public function index(SmtpSettings $settings)
    {
        try {
            $display=$settings->display();
        } catch (\Throwable) {
            return response()->view('platform.settings',['smtp'=>null],503);
        }
        return response()->view('platform.settings',['smtp'=>$display])->header('Cache-Control','private, no-store');
    }

    public function submit(Request $request,SmtpSettings $settings,SmtpConnectionTest $probe)
    {
        $input=$request->validate(['host'=>['required','string','max:253',function($field,$host,$fail){
            if (!filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)) $fail('Informe o nome do servidor SMTP.');
        }], 'port'=>'required|integer|between:1,65535','security'=>'required|in:tls,starttls',
            'username'=>['required','string','max:255','not_regex:/[[:cntrl:]]/'],'password'=>'nullable|string|max:4096',
            'from_address'=>'required|email|max:254','from_name'=>['required','string','max:180','not_regex:/[[:cntrl:]]/'],
            'version'=>'required|string|size:64']);
        try {
            $candidate=$settings->candidate($input);
            $probe->check($candidate);
            if ($request->routeIs('platform.settings.smtp.save')) {
                $settings->save($candidate,$input['version']);
                $actor = $request->attributes->get('platformAdmin');
                try {
                    PlatformAudit::create([
                        'platform_admin_id' => $actor->id,
                        'actor_name' => $actor->name,
                        'action' => 'platform.smtp_updated',
                        'changes' => array_intersect_key($candidate, array_flip(['host','port','security','from_address'])),
                    ]);
                } catch (\Throwable) {
                    return redirect()->route('platform.settings')->withErrors(['smtp'=>'Configuração salva. Não foi possível registrar a alteração na auditoria.']);
                }
                return redirect()->route('platform.settings')->with('success','Configuração SMTP testada e salva.');
            }
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['smtp'=>'Não foi possível acessar o arquivo privado de configuração SMTP.']);
        }
        return redirect()->route('platform.settings')->withInput($request->except(['password','_token']))
            ->with('success','Conexão TLS e autenticação SMTP confirmadas. Nenhum e-mail foi enviado.');
    }
}
