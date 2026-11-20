#!/usr/bin/env bash
# Scoped Hostinger rollout. The operator supplies the baseline from the preceding release.
set -Eeuo pipefail
umask 077
[[ $EUID == 0 && ${1:-} == --apply ]] || { echo 'Uso: --apply DIRETORIO_PRIVADO_DA_RELEASE'; exit 64; }
release=${2:?}
[[ $release =~ ^/var/lib/vendra/releases/[a-f0-9]{40}$ && $(realpath "$release") == "$release" && $(stat -c '%u-%a' "$release") == 0-700 ]]
stage="$release/stage"
manifest="$release/runtime.sha256"
live=/srv/reporvexo
exec 9>/run/lock/vendra-fiscal-deploy.lock
flock -n 9 || { echo 'Outra implantação em andamento.' >&2; exit 1; }
[[ $(realpath "$live") == "$live" ]]
python3 "$stage/ops/qa-release.py" verify "$stage" "$manifest"
python3 - "$release" "$live" <<'PY'
import hashlib,pathlib,re,sys
release,live=map(pathlib.Path,sys.argv[1:])
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
if set(new)!=set(baseline) or set(protected)&set(new):raise SystemExit('Escopo divergente.')
for name,h in {**baseline,**protected}.items():
    p=live/name
    if p.resolve()!=p or (h=='-' and p.exists()) or (h!='-' and (not p.is_file() or hashlib.sha256(p.read_bytes()).hexdigest()!=h)):raise SystemExit('Baseline divergente: '+name)
for name in new:
    if name not in ('bootstrap/app.php','composer.json') and not name.startswith(('app/','config/','database/migrations/','ops/','public/','resources/views/','routes/')):raise SystemExit('Arquivo fora do escopo: '+name)
    if name in ('ops/apache.conf','public/index.php','resources/views/welcome.blade.php') or name.startswith(('public/brand/','public/marketing')):raise SystemExit('Arquivo protegido: '+name)
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
systemctl is-active --quiet vendra.service
health
journal=$(mktemp -d "$release/attempt.XXXXXX")
fiscal_resume=0
web_paused=0
overlay=0
clean_exit() {
    local status=$?
    trap - EXIT
    if (( status && overlay )); then
        systemctl stop vendra.service || exit 1
        while read -r h p; do
            if [[ "$h" == - ]]; then rm -f -- "$live/$p"; else cp -p -- "$journal/previous/$p" "$live/$p" || exit 1; fi
        done < "$release/baseline.sha256"
        cache > "$journal/cache-restore.log" 2>&1 || exit 1
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
overlay=1
while read -r h p; do
    d=$(dirname "$p")
    if [[ ! -d "$live/$d" ]]; then install -d -o root -g vendra-web -m750 "$live/$d"; fi
    install -m640 -o root -g vendra-web "$stage/$p" "$live/$p"
done < "$manifest"
sha256sum -c "$manifest" > "$journal/runtime-check.log"
while read -r h p; do runuser -u vendra-web -- test -r "$live/$p"; done < "$manifest"
for migration in database/migrations/2026_11_20_000001_add_platform_mfa.php database/migrations/2026_11_20_000004_add_company_activation.php; do
    artisan migrate --database=central --force --path="$migration" >> "$journal/migrations.log" 2>&1
done
cache > "$journal/cache.log" 2>&1
systemctl start vendra.service
health
web_paused=0
if ((fiscal_resume)); then systemctl start vendra-fiscal.timer; fiscal_resume=0; fi
printf '%s\n' "$VENDRA_RELEASE_COMMIT" > "$journal/completed"
echo 'Pacote validado e publicado. Backup e hashes registrados no diretório privado da release.'
