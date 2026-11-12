#!/bin/sh
# Run as root only, after the release has been extracted and Composer completed.
set -eu
[ "$(id -u)" = 0 ] || { printf '%s\n' 'Execute como root.' >&2; exit 1; }
vendra_root=/srv/reporvexo
[ -d "$vendra_root" ] && [ ! -L "$vendra_root" ] || exit 1
[ "$(realpath "$vendra_root")" = "$vendra_root" ] || exit 1
id vendra-web >/dev/null
# Refuse symlinks that could redirect recursive permission changes to other apps.
if find "$vendra_root" -type l -print -quit | grep -q .; then
 printf '%s\n' 'Symlink encontrado. Revise-o antes de normalizar permissões.' >&2
 exit 1
fi
chown -R root:vendra-web "$vendra_root"
find "$vendra_root" -type d -exec chmod 750 {} +
find "$vendra_root" -type f -exec chmod 640 {} +
for vendra_private in .env.testing; do
 if [ -f "$vendra_root/$vendra_private" ]; then
  chown root:root "$vendra_root/$vendra_private"
  chmod 600 "$vendra_root/$vendra_private"
 fi
done
chown -R vendra-web:vendra-web "$vendra_root/storage" "$vendra_root/bootstrap/cache"
# PHP/Composer executables are intentionally called with `php`, not direct exec.
