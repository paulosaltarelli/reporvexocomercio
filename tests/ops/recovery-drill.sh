#!/usr/bin/env bash
# Synthetic end-to-end drill. Supply a local MySQL image to exercise the SQL import.
set -euo pipefail
umask 077
root=$(cd "$(dirname "$0")/../.." && pwd)
work=$(mktemp -d)
export GNUPGHOME="$work/keys"
trap 'gpgconf --kill gpg-agent >/dev/null 2>&1 || true; rm -rf "$work"' EXIT
mkdir -m700 -p "$GNUPGHOME" "$work/snapshot" "$work/external" "$work/files/reporvexo" "$work/files/controlplane"
gpg --batch --passphrase '' --quick-gen-key recovery-fixture@example.invalid rsa2048 encr 1d >/dev/null 2>&1
recipient=$(gpg --batch --with-colons --list-keys | awk -F: '$1=="fpr"{print $10; exit}')
echo 'APP_KEY=synthetic-fixture' > "$work/files/reporvexo/.env"
echo '{}' > "$work/files/controlplane/schema.json"
tar -C "$work/files" -czf "$work/snapshot/application.tar.gz" reporvexo
tar -C "$work/files" -czf "$work/snapshot/controlplane.tar.gz" controlplane
printf '%s\n' vendra_central vendra_t_recoveryfixture > "$work/snapshot/databases.txt"
cat > "$work/snapshot/databases.sql" <<'SQL'
CREATE DATABASE vendra_central;
CREATE TABLE vendra_central.tenants (db_name VARCHAR(100));
INSERT INTO vendra_central.tenants VALUES ('vendra_t_recoveryfixture');
CREATE DATABASE vendra_t_recoveryfixture;
CREATE TABLE vendra_t_recoveryfixture.sales (id INT PRIMARY KEY);
INSERT INTO vendra_t_recoveryfixture.sales VALUES (1);
SQL
(cd "$work/snapshot" && sha256sum application.tar.gz controlplane.tar.gz databases.sql databases.txt > SHA256SUMS)
package="$root/ops/recovery-package.py"
hash=$("$package" export "$work/snapshot" "$work/external" "$recipient" --local-drill | cut -d' ' -f1)
"$package" restore "$work/external/snapshot.tar.gpg" "$hash" "$work/restored"
cmp "$work/files/reporvexo/.env" "$work/restored/reporvexo/.env"
cmp "$work/files/controlplane/schema.json" "$work/restored/controlplane/schema.json"
cmp "$work/snapshot/databases.sql" "$work/restored/databases.sql"
if "$package" restore "$work/external/snapshot.tar.gpg" "$hash" "$work/restored" >/dev/null 2>&1; then exit 1; fi
echo 'corrupt' >> "$work/external/snapshot.tar.gpg"
if "$package" restore "$work/external/snapshot.tar.gpg" "$hash" "$work/corrupt" >/dev/null 2>&1; then exit 1; fi
[[ ! -e "$work/corrupt" ]]
if [[ $# == 1 ]]; then "$root/ops/recovery-mysql-drill.sh" "$work/restored" "$1"; fi
echo "Ensaio de recuperação com dados sintéticos concluído."
