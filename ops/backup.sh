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
# Only schema names declared by the app and conforming to its reserved prefix.
while IFS= read -r vendra_db; do
 case "$vendra_db" in
  vendra_central) ;;
  vendra_t_*) case "$vendra_db" in *[!a-z0-9_]*|vendra_t_test_*) exit 1;; esac ;;
  *) exit 1;;
 esac
done < "$vendra_tmp/databases.txt"
# Word splitting here is intentional after the restrictive schema validation.
# shellcheck disable=SC2046
mysqldump --single-transaction --quick --routines --events --triggers --hex-blob --databases $(cat "$vendra_tmp/databases.txt") > "$vendra_tmp/databases.sql"
# Includes APP_KEY and central DB configuration; never publish this archive.
tar -C /srv --exclude=reporvexo/.env.testing --exclude=reporvexo/storage/logs --exclude=reporvexo/storage/framework/cache --exclude=reporvexo/storage/framework/sessions --exclude=reporvexo/storage/framework/views -czf "$vendra_tmp/application.tar.gz" reporvexo
(cd "$vendra_tmp" && sha256sum databases.txt databases.sql application.tar.gz > SHA256SUMS)
vendra_suffix=${vendra_tmp##*.incomplete.}
vendra_final="$vendra_backup_root/$(date -u +%Y%m%dT%H%M%SZ)-$vendra_suffix"
mv "$vendra_tmp" "$vendra_final"
printf 'Backup local criado em %s. Ainda requer cópia cifrada externa e ensaio de restauração.\n' "$vendra_final"
