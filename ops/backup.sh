#!/bin/sh
# Local recovery package. It is not an off-site backup or a tested restore.
set -eu
umask 0077
[ "$(id -u)" = 0 ] || { printf '%s\n' 'Execute como root.' >&2; exit 1; }
[ "${VENDRA_BACKUP_ACK_QUIESCED:-}" = 1 ] || {
 printf '%s\n' 'Pause provisionamentos, migrações e escritas Vendra; confirme VENDRA_BACKUP_ACK_QUIESCED=1.' >&2
 exit 1
}
command -v mysqldump >/dev/null
[ -f /srv/reporvexo/.env ] || exit 1
vendra_backup_root=/var/backups/vendra
if [ -L "$vendra_backup_root" ]; then exit 1; fi
install -d -m 700 "$vendra_backup_root"
vendra_tmp=$(mktemp -d "$vendra_backup_root/.incomplete.XXXXXX")
printf '%s\n' 'vendra_central' > "$vendra_tmp/databases.txt"
mysql --batch --skip-column-names --execute='SELECT DISTINCT db_name FROM vendra_central.tenants ORDER BY db_name' >> "$vendra_tmp/databases.txt"
# Read only the configured shared database name, never administrative credentials.
vendra_shared_db=$(python3 - <<'PY_SHARED'
import json,pathlib,re,stat
p=pathlib.Path('/var/lib/vendra/controlplane/shared.json')
if not p.exists():
 print('');raise SystemExit(0)
if p.is_symlink() or p.resolve()!=p or p.stat().st_uid!=0 or stat.S_IMODE(p.stat().st_mode)&0o077:raise SystemExit('Private control-plane file rejected.')
for parent in [p.parent,p.parent.parent]:
 if parent.is_symlink() or parent.resolve()!=parent or parent.stat().st_uid!=0 or stat.S_IMODE(parent.stat().st_mode)&0o022:raise SystemExit('Private control-plane directory rejected.')
v=json.loads(p.read_text()).get('database')
if not isinstance(v,str) or not re.fullmatch(r'vendra_shared_[a-z0-9_]{1,40}',v) or v.startswith(('vendra_shared_qa_','vendra_shared_poc_','vendra_shared_test_')):raise SystemExit('Shared backup database rejected.')
print(v)
PY_SHARED
)
# Only schema names declared by the app and conforming to its reserved prefix.
while IFS= read -r vendra_db; do
 case "$vendra_db" in
  vendra_central) ;;
  vendra_t_*) case "$vendra_db" in *[!a-z0-9_]*|vendra_t_test_*) exit 1;; esac ;;
  *) [ -n "$vendra_shared_db" ] && [ "$vendra_db" = "$vendra_shared_db" ] || exit 1;;
 esac
done < "$vendra_tmp/databases.txt"
# Word splitting here is intentional after the restrictive schema validation.
# shellcheck disable=SC2046
mysqldump --single-transaction --quick --routines --events --triggers --hex-blob --databases $(cat "$vendra_tmp/databases.txt") > "$vendra_tmp/databases.sql"
# Includes APP_KEY and central DB configuration; never publish this archive.
tar -C /srv --exclude=reporvexo/.env.testing --exclude=reporvexo/storage/logs --exclude=reporvexo/storage/framework/cache --exclude=reporvexo/storage/framework/sessions --exclude=reporvexo/storage/framework/views -czf "$vendra_tmp/application.tar.gz" reporvexo
# Root-only control-plane recovery material is separate from the application archive.
# Database account passwords remain encrypted in the central SQL dump; APP_KEY is in app.
if [ -n "$vendra_shared_db" ]; then
 python3 - <<'PY_FILES'
import pathlib,stat
for name in ['shared.json','schema.json']:
 p=pathlib.Path('/var/lib/vendra/controlplane')/name
 if p.exists() and (p.is_symlink() or not p.is_file() or p.stat().st_uid!=0 or stat.S_IMODE(p.stat().st_mode)&0o077):raise SystemExit('Private control-plane recovery file rejected.')
PY_FILES
 if [ -f /var/lib/vendra/controlplane/schema.json ]; then
  tar -C /var/lib/vendra -czf "$vendra_tmp/controlplane.tar.gz" controlplane/shared.json controlplane/schema.json
 else
  tar -C /var/lib/vendra -czf "$vendra_tmp/controlplane.tar.gz" controlplane/shared.json
 fi
 (cd "$vendra_tmp" && sha256sum databases.txt databases.sql application.tar.gz controlplane.tar.gz > SHA256SUMS)
else
 (cd "$vendra_tmp" && sha256sum databases.txt databases.sql application.tar.gz > SHA256SUMS)
fi
vendra_smtp=/var/lib/vendra/smtp-admin/settings.enc
if [ -e "$vendra_smtp" ] || [ -L "$vendra_smtp" ]; then
 [ "$(realpath "$vendra_smtp")" = "$vendra_smtp" ] && [ -f "$vendra_smtp" ] && [ "$(stat -c '%U:%a:%h' "$vendra_smtp")" = vendra-web:600:1 ] || exit 1
 tar -C /var/lib/vendra -czf "$vendra_tmp/smtp.tar.gz" smtp-admin/settings.enc
 (cd "$vendra_tmp" && sha256sum smtp.tar.gz >> SHA256SUMS)
fi
vendra_suffix=${vendra_tmp##*.incomplete.}
vendra_final="$vendra_backup_root/$(date -u +%Y%m%dT%H%M%SZ)-$vendra_suffix"
mv "$vendra_tmp" "$vendra_final"
printf 'Backup local criado em %s. Ainda requer cópia cifrada externa e ensaio de restauração.\n' "$vendra_final"
