#!/usr/bin/python3
"""Configuração interativa da caixa oficial da Hostinger. Nunca envia mensagem."""
import fcntl
import getpass
import hashlib
import os
from pathlib import Path
import re
import smtplib
import ssl
import subprocess
import sys
import tempfile

APP = Path('/srv/reporvexo')
ENV = APP / '.env'
BACKUPS = Path('/var/lib/vendra/smtp-config')
MAILBOX = 'contato@vexogestao.com.br'
HOST = 'smtp.hostinger.com'
PHP = ['runuser', '-u', 'vendra-web', '--', '/usr/bin/php', '-c', '/opt/reporvexo-tools/php/cli.ini']
VERIFY = r'''
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$p=stream_get_contents(STDIN);
foreach (["default"=>"smtp","mailers.smtp.scheme"=>"smtps","mailers.smtp.host"=>"smtp.hostinger.com",
          "mailers.smtp.port"=>"465","mailers.smtp.username"=>"contato@vexogestao.com.br",
          "mailers.smtp.password"=>$p,"from.address"=>"contato@vexogestao.com.br"] as $k=>$v)
    if ((string)config("mail.".$k)!==$v) exit(1);
if (config("mail.mailers.smtp.url")) exit(1);
'''

def replace_env(data, values):
    lines = data.decode().splitlines(keepends=True)
    for key, value in values.items():
        matches = [i for i, line in enumerate(lines) if re.match(r'^\s*(?:export\s+)?' + key + r'\s*=', line)]
        if len(matches) > 1:
            raise ValueError('Chave duplicada')
        quoted = value.replace('\\', '\\\\').replace('"', '\\"').replace('$', '\\$')
        replacement = f'{key}="{quoted}"\n' if value else f'{key}=\n'
        if matches:
            i = matches[0]
            prior = lines[i].split('=', 1)[1].strip()
            if prior.startswith(('"', "'")) and (len(prior) < 2 or prior[-1] != prior[0]):
                raise ValueError('Valor multilinha')
            lines[i] = replacement
        else:
            if lines and not lines[-1].endswith('\n'):
                lines[-1] += '\n'
            lines.append(replacement)
    return ''.join(lines).encode()

def put_env(data):
    info = ENV.stat()
    with tempfile.NamedTemporaryFile(dir=APP, delete=False) as file:
        path = Path(file.name)
        try:
            file.write(data)
            file.flush()
            os.fchown(file.fileno(), info.st_uid, info.st_gid)
            os.fchmod(file.fileno(), info.st_mode & 0o777)
            os.fsync(file.fileno())
            os.replace(path, ENV)
        finally:
            path.unlink(missing_ok=True)

def refresh(cached):
    cmd = 'config:cache' if cached else 'config:clear'
    subprocess.run(PHP + ['artisan', cmd], cwd=APP, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True)

def main():
    if os.geteuid() or not sys.stdin.isatty() or not sys.stdout.isatty() or sys.argv[1:] not in ([], ['--rollback']):
        sys.exit('Execute como root em um terminal privado, sem argumentos ou com --rollback.')
    os.umask(0o077)
    if ENV.resolve() != ENV or not ENV.is_file() or ENV.stat().st_uid:
        sys.exit('Arquivo de ambiente inesperado.')
    BACKUPS.mkdir(mode=0o700, parents=True, exist_ok=True)
    if BACKUPS.resolve() != BACKUPS or BACKUPS.stat().st_uid or BACKUPS.stat().st_mode & 0o077:
        sys.exit('Diretório de backup inseguro.')
    with open('/run/lock/vendra-fiscal-deploy.lock', 'a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        previous = ENV.read_bytes()
        cached = (APP / 'bootstrap/cache/config.php').is_file()
        if sys.argv[1:]:
            candidates = sorted(BACKUPS.glob('*/applied'), key=lambda p: p.stat().st_mtime_ns, reverse=True)
            if not candidates or candidates[0].read_text() != hashlib.sha256(previous).hexdigest():
                sys.exit('Nenhuma configuração correspondente para reverter.')
            marker = candidates[0]
            replacement = (marker.parent / 'environment').read_bytes()
        else:
            password = getpass.getpass(f'Senha SMTP de {MAILBOX} (oculta): ')
            if not password or any(ord(c) < 32 or ord(c) == 127 for c in password):
                sys.exit('Senha vazia ou com caractere de controle.')
            with smtplib.SMTP_SSL(HOST, 465, timeout=15, context=ssl.create_default_context()) as smtp:
                smtp.login(MAILBOX, password)
            values = dict(MAIL_MAILER='smtp', MAIL_HOST=HOST, MAIL_PORT='465', MAIL_SCHEME='smtps',
                          MAIL_URL='', MAIL_USERNAME=MAILBOX, MAIL_PASSWORD=password,
                          MAIL_FROM_ADDRESS=MAILBOX, MAIL_FROM_NAME='Vendra')
            replacement = replace_env(previous, values)
            folder = Path(tempfile.mkdtemp(prefix='backup-', dir=BACKUPS))
            (folder / 'environment').write_bytes(previous)
        try:
            put_env(replacement)
            refresh(cached)
            if sys.argv[1:]:
                marker.unlink()
            else:
                subprocess.run(PHP + ['-r', VERIFY], cwd=APP, input=password.encode(),
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True)
                (folder / 'applied').write_text(hashlib.sha256(replacement).hexdigest())
        except BaseException:
            put_env(previous)
            refresh(cached)
            raise
    print('Configuração anterior restaurada.' if sys.argv[1:] else 'TLS, autenticação e configuração do Vendra confirmados. Nenhuma mensagem enviada.')

if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, subprocess.SubprocessError, smtplib.SMTPException):
        sys.exit('Operação não concluída. Verifique lock, autenticação, ambiente e cache no terminal privado.')
