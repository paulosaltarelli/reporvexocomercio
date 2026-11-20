#!/usr/bin/env bash
# Scoped Hostinger rollout. The operator supplies the baseline from the preceding release.
set -Eeuo pipefail
umask 077
mode=${1:-}
[[ $EUID == 0 && ( $mode == --apply || $mode == --apply-licensing-v1 || $mode == --apply-nfse-v1 || $mode == --apply-code-only || $mode == --apply-accesses-v1 || $mode == --apply-central-settings ) ]] || { echo 'Uso: --apply, --apply-licensing-v1, --apply-nfse-v1 ou --apply-code-only / --apply-accesses-v1 / --apply-central-settings DIRETORIO_PRIVADO_DA_RELEASE'; exit 64; }
release=${2:?}
[[ $release =~ ^/var/lib/vendra/releases/[a-f0-9]{40}$ && $(realpath "$release") == "$release" && $(stat -c '%u-%a' "$release") == 0-700 ]]
stage="$release/stage"
manifest="$release/runtime.sha256"
live=/srv/reporvexo
exec 9>/run/lock/vendra-fiscal-deploy.lock
flock -n 9 || { echo 'Outra implantação em andamento.' >&2; exit 1; }
[[ $(realpath "$live") == "$live" ]]
python3 "$stage/ops/qa-release.py" verify "$stage" "$manifest"
python3 - "$release" "$live" "$mode" <<'PY'
import hashlib,json,os,pathlib,re,sys
release,live=map(pathlib.Path,sys.argv[1:3])
licensing=sys.argv[3]=="--apply-licensing-v1"
nfse=sys.argv[3]=="--apply-nfse-v1"
def read(path,allow_absent):
    entries={}
    for line in path.read_text().splitlines():
        digest,sep,name=line.partition('  ')
        p=pathlib.Path(name)
        if (not re.fullmatch('[0-9a-f]{64}',digest) and not (allow_absent and digest=='-')) or not sep or not re.fullmatch('[A-Za-z0-9_./-]+',name) or p.is_absolute() or '..' in p.parts or name in entries:raise SystemExit('Manifesto inválido.')
        entries[name]=digest
    return entries
baseline=read(release/'baseline.sha256',True)
new=read(release/'runtime.sha256',False)
protected=read(release/'protected.sha256',False)
removed=read(release/'removed.sha256',False) if nfse else {}
if set(new)&set(removed) or (set(new)|set(removed))!=set(baseline) or set(protected)&set(baseline):raise SystemExit('Escopo divergente.')
if nfse:
    known=set(json.loads(pathlib.Path(os.environ['VENDRA_QA_EVIDENCE']).read_bytes())['files'])
    for name,h in removed.items():
        if name in known or h!=baseline[name] or not (name.startswith('app/Fiscal/Nfse/') or name.startswith('resources/views/fiscal/nfse/')):
            raise SystemExit('Remoção fora do escopo da DPS: '+name)

for name,h in {**baseline,**protected}.items():
    p=live/name
    if p.resolve()!=p or (h=='-' and p.exists()) or (h!='-' and (not p.is_file() or hashlib.sha256(p.read_bytes()).hexdigest()!=h)):raise SystemExit('Baseline divergente: '+name)
if licensing:
    migrations={
        'database/migrations/2026_11_20_000001_create_platform_contracts.php',
        'database/migrations/2026_11_20_000002_create_platform_contract_installments.php',
        'database/migrations/2026_11_20_000003_create_platform_contract_payments.php',
        'database/migrations/2026_11_20_000005_create_platform_c6_pix_charges.php',
    }
    if {name for name in new if name.startswith('database/')}!=migrations:raise SystemExit('Migrações fora do escopo da V1.')
    if 'resources/views/welcome.blade.php' not in new:raise SystemExit('Landing da versão ausente.')
if nfse and {name for name in new if name.startswith('database/')}!={'database/migrations/2026_11_20_000006_add_contract_grace_periods.php'}:
    raise SystemExit('A revisão NFS-e e carências requer somente a migração central dos prazos contratuais.')
if sys.argv[3]=='--apply-accesses-v1' and {name for name in new if name.startswith('database/')}!={'database/migrations/2026_11_20_000007_create_platform_installer_invitations.php'}:
    raise SystemExit('A release de acessos requer apenas a migração central de convites.')
if sys.argv[3]=='--apply-code-only' and any(name.startswith('database/') for name in new):raise SystemExit('Release somente de código não aceita migrações.')
if sys.argv[3]=='--apply-central-settings':
    if any(name.startswith('database/') for name in new):raise SystemExit('Configurações da Central não aceitam migrações.')
    expected={'app/Http/Controllers/Platform/AuthController.php','app/Http/Middleware/PlatformAuthenticated.php','app/Platform/SupportAccess.php','resources/views/platform/login.blade.php'}
    if not expected<=set(new):raise SystemExit('A transição requer os quatro arquivos da autenticação testada.')
if sys.argv[3] not in ('--apply','--apply-central-settings'):
    legacy={'app/Http/Controllers/Platform/AuthController.php','app/Http/Middleware/PlatformAuthenticated.php','app/Platform/SupportAccess.php','resources/views/platform/login.blade.php'}
    if not legacy<=set(protected):raise SystemExit('Login de transição precisa estar protegido.')
for name in new:
    if name not in ('bootstrap/app.php','composer.json') and not name.startswith(('app/','config/','database/migrations/','ops/','public/','resources/views/','routes/')):raise SystemExit('Arquivo fora do escopo: '+name)
    if (name=='resources/views/welcome.blade.php' and not (licensing or nfse)) or name in ('ops/apache.conf','public/index.php') or name.startswith(('public/brand/','public/marketing')):raise SystemExit('Arquivo protegido: '+name)
PY
cd "$live"
phpcli=(/usr/bin/php -c /opt/reporvexo-tools/php/cli.ini)
artisan() { runuser -u vendra-web -- "${phpcli[@]}" artisan "$@"; }
cache() { artisan config:clear && artisan route:clear && artisan view:clear && artisan view:cache; }
health() {
    local i
    for ((i=0;i<12;i++)); do
        if curl -fsS --max-time 4 --resolve 2.25.146.67:8445:127.0.0.1 -o /dev/null https://2.25.146.67:8445/app/vexo/login &&
           curl -fsS --max-time 4 --resolve 2.25.146.67:8445:127.0.0.1 -o /dev/null https://2.25.146.67:8445/vexo-admin/login; then return 0; fi
        sleep 1
    done
    return 1
}
if [[ $mode != --apply ]]; then
    for unit in vendra-licensing.timer vendra-licensing.service; do
        case $(systemctl show "$unit" -p ActiveState --value) in inactive|failed) ;; *) echo "Aguarde a parada de $unit." >&2; exit 1;; esac
    done
fi
systemctl is-active --quiet vendra.service
health
journal=$(mktemp -d "$release/attempt.XXXXXX")
fiscal_resume=0
web_paused=0
overlay=0
smtp_dropin=/etc/systemd/system/vendra.service.d/40-smtp-admin.conf
smtp_unit_added=0
clean_exit() {
    local status=$?
    trap - EXIT
    if (( status && overlay )); then
        web_paused=1
        systemctl stop vendra.service || exit 1
        while read -r h p; do
            if [[ "$h" == - ]]; then rm -f -- "$live/$p"; else cp -p -- "$journal/previous/$p" "$live/$p" || exit 1; fi
        done < "$release/baseline.sha256"
        cache > "$journal/cache-restore.log" 2>&1 || exit 1
    fi
    if ((status && smtp_unit_added)); then
        rm -f -- "$smtp_dropin"
        systemctl daemon-reload || exit 1
    fi
    if ((web_paused)); then systemctl start vendra.service && health || exit 1; fi
    if ((fiscal_resume)); then systemctl start vendra-fiscal.timer || exit 1; fi
    exit "$status"
}
trap clean_exit EXIT
if systemctl is-active --quiet vendra-fiscal.timer; then fiscal_resume=1; systemctl stop vendra-fiscal.timer; fi
for ((i=0;i<300;i++)); do
    [[ $(systemctl show -p ActiveState --value vendra-fiscal.service) == inactive ]] && break
    sleep 2
done
[[ $(systemctl show -p ActiveState --value vendra-fiscal.service) == inactive ]]
web_paused=1
systemctl stop vendra.service
VENDRA_BACKUP_ACK_QUIESCED=1 sh "$live/ops/backup.sh" > "$journal/backup.log"
backup=$(sed -n 's/^Backup local criado em \(\/var\/backups\/vendra\/[0-9TZ]*-[A-Za-z0-9]*\)\..*/\1/p' "$journal/backup.log")
[[ -n "$backup" && $(realpath "$backup") == "$backup" ]]
(cd "$backup" && sha256sum -c SHA256SUMS) > "$journal/backup-check.log"
while read -r h p; do
    if [[ $h != - ]]; then mkdir -p "$journal/previous/$(dirname "$p")"; cp -p "$live/$p" "$journal/previous/$p"; fi
done < "$release/baseline.sha256"
if [[ $mode == --apply-central-settings ]]; then
    smtp_dir=/var/lib/vendra/smtp-admin
    [[ $(realpath -m "$smtp_dir") == "$smtp_dir" ]]
    if [[ ! -e $smtp_dir ]]; then install -d -o vendra-web -g vendra-web -m700 "$smtp_dir"; fi
    [[ $(stat -c '%U:%G:%a' "$smtp_dir") == vendra-web:vendra-web:700 ]]
    [[ $(realpath -m "$smtp_dropin") == "$smtp_dropin" ]]
    install -d -m755 "$(dirname "$smtp_dropin")"
    printf '[Service]\nReadWritePaths=/var/lib/vendra/smtp-admin\n' > "$journal/smtp-admin.conf"
    if [[ -e $smtp_dropin ]]; then
        cmp "$journal/smtp-admin.conf" "$smtp_dropin"
    else
        smtp_unit_added=1
        install -m644 "$journal/smtp-admin.conf" "$smtp_dropin"
        systemctl daemon-reload
    fi
fi
overlay=1
if [[ $mode == --apply-nfse-v1 ]]; then
    while read -r h p; do rm -- "$live/$p"; done < "$release/removed.sha256"
fi
while read -r h p; do
    d=$(dirname "$p")
    if [[ ! -d "$live/$d" ]]; then install -d -o root -g vendra-web -m750 "$live/$d"; fi
    install -m640 -o root -g vendra-web "$stage/$p" "$live/$p"
done < "$manifest"
sha256sum -c "$manifest" > "$journal/runtime-check.log"
while read -r h p; do runuser -u vendra-web -- test -r "$live/$p"; done < "$manifest"
migrations=(database/migrations/2026_11_20_000001_add_platform_mfa.php database/migrations/2026_11_20_000004_add_company_activation.php)
if [[ $mode == --apply-licensing-v1 ]]; then
    migrations=(database/migrations/2026_11_20_000001_create_platform_contracts.php database/migrations/2026_11_20_000002_create_platform_contract_installments.php database/migrations/2026_11_20_000003_create_platform_contract_payments.php database/migrations/2026_11_20_000005_create_platform_c6_pix_charges.php)
elif [[ $mode == --apply-accesses-v1 ]]; then
    migrations=(database/migrations/2026_11_20_000007_create_platform_installer_invitations.php)
elif [[ $mode == --apply-code-only || $mode == --apply-central-settings ]]; then
    migrations=()
elif [[ $mode == --apply-nfse-v1 ]]; then
    migrations=(database/migrations/2026_11_20_000006_add_contract_grace_periods.php)
fi
if [[ $mode != --apply ]]; then
    # Check effective configuration without displaying credentials before reopening the application.
    runuser -u vendra-web -- "${phpcli[@]}" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); foreach (["fiscal.product_invoices_enabled","c6pix.enabled","c6pix.sandbox.enabled","licensing_worker.reconcile","licensing_worker.issue"] as $key) if (config($key)!==false) exit(1);'
fi
if [[ $mode == --apply-central-settings ]]; then
    artisan config:clear > "$journal/settings-config.log"
    runuser -u vendra-web -- "${phpcli[@]}" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if(config("platform.mfa_required")!==false)exit(1);'
fi
for migration in "${migrations[@]}"; do
    artisan migrate --database=central --force --path="$migration" >> "$journal/migrations.log" 2>&1
done
cache > "$journal/cache.log" 2>&1
systemctl start vendra.service
health
web_paused=0
if ((fiscal_resume)); then systemctl start vendra-fiscal.timer; fiscal_resume=0; fi
printf '%s\n' "$VENDRA_RELEASE_COMMIT" > "$journal/completed"
echo 'Pacote validado e publicado. Backup e hashes registrados no diretório privado da release.'
