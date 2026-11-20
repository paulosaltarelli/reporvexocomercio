@php
    $isInvitation = $kind === 'installer_invitation';
    $isPreview = $preview ?? false;
    $heading = $isInvitation ? 'Convite para implantação' : 'Sua loja está pronta';
    $action = $isInvitation ? 'Aceitar convite' : 'Ativar minha conta';
    $logo = $isPreview ? '/brand/vexo-logo-dark.png' : $message->embed(public_path('brand/vexo-logo-dark.png'));
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $heading }} · Vendra</title>
</head>
<body style="margin:0;padding:0;background-color:#eef3f8;color:#142640;font-family:Arial,Helvetica,sans-serif;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">
        {{ $isInvitation ? 'Aceite o convite para acessar a loja' : 'Crie sua senha para administrar a loja' }} {{ $companyName }} no Vendra.
    </div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eef3f8">
        <tr><td align="center" style="padding:24px 12px;">
            <!--[if mso]><table role="presentation" width="580" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="width:100%;max-width:580px;background-color:#ffffff;border:1px solid #dce5f0;border-top:4px solid #1463eb;border-radius:10px;">
                <tr><td style="padding:24px 24px 20px;border-bottom:1px solid #e5ebf3;">
                    <img src="{{ $logo }}" alt="Vexo" width="126" height="42" style="display:block;border:0;width:126px;height:42px;color:#12284b;font:700 24px Arial,sans-serif;">
                </td></tr>
                <tr><td style="padding:26px 24px 22px;color:#142640;font:16px/1.55 Arial,Helvetica,sans-serif;">
                    <h1 style="font:700 27px/1.2 Arial,Helvetica,sans-serif;color:#10213d;margin:0 0 20px;">{{ $heading }}</h1>
                    <p style="margin:0 0 12px;">Olá, {{ $recipientName }}.</p>
                    @if($isInvitation)
                        <p style="margin:0 0 18px;">A Vexo convidou você para acessar a loja <strong>{{ $companyName }}</strong> no Vendra. Abra o convite, informe o e-mail que recebeu esta mensagem e crie sua senha.</p>
                    @else
                        <p style="margin:0 0 16px;">A loja <strong>{{ $companyName }}</strong> está pronta no Vendra. Para ativar seu acesso de administrador, abra a página de ativação, informe o e-mail que recebeu esta mensagem e o código abaixo. Em seguida, crie sua senha.</p>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#edf4fe" style="margin:0 0 20px;border:1px solid #d5e4fb;border-radius:6px;">
                            <tr><td style="padding:14px 16px;">
                                <p style="margin:0 0 5px;color:#405879;font:700 13px/1.4 Arial,Helvetica,sans-serif;">Código de ativação</p>
                                @if($isPreview)
                                    <span style="color:#405879;font:14px/1.5 Arial,Helvetica,sans-serif;">Código oculto na visualização</span>
                                @else
                                    <code style="font:700 15px/1.5 Consolas,'Courier New',monospace;color:#10213d;overflow-wrap:anywhere;word-break:break-all;">{{ $activationCode }}</code>
                                @endif
                            </td></tr>
                        </table>
                    @endif
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                        <tr><td align="center" bgcolor="#1456c7" style="border-radius:6px;mso-padding-alt:13px 22px;">
                            @if($isPreview)
                                <span style="display:inline-block;padding:13px 22px;color:#ffffff;font:700 16px/1.2 Arial,Helvetica,sans-serif;">{{ $action }}</span>
                            @else
                                <a href="{{ $activationUrl }}" style="display:inline-block;padding:13px 22px;border-radius:6px;color:#ffffff;text-decoration:none;font:700 16px/1.2 Arial,Helvetica,sans-serif;mso-padding-alt:0;">{{ $action }}</a>
                            @endif
                        </td></tr>
                    </table>
                    <p style="margin:18px 0 0;color:#40516a;font:14px/1.55 Arial,Helvetica,sans-serif;">{{ $isInvitation ? 'O convite vale por 24 horas e pode ser usado uma vez. Após o aceite, seu acesso a esta loja continua enquanto a Central o mantiver habilitado, inclusive após a conclusão da implantação.' : 'O código vale por 24 horas e pode ser usado uma vez. Depois de ativar a conta, entre no Vendra com seu e-mail e a senha escolhida.' }}</p>
                </td></tr>
                <tr><td style="padding:18px 24px;border-top:1px solid #e5ebf3;color:#50617a;font:13px/1.55 Arial,Helvetica,sans-serif;">
                    <p style="margin:0 0 4px;">Se você não reconhece esta solicitação, ignore esta mensagem.</p>
                    <p style="margin:0;color:#233c60;font-weight:700;">Vendra · um produto Vexo</p>
                </td></tr>
            </table>
            <!--[if mso]></td></tr></table><![endif]-->
        </td></tr>
    </table>
</body></html>
