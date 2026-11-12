#!/bin/sh
set -eu
vendra_cert=/etc/letsencrypt/live/2.25.146.67/fullchain.pem
vendra_digest=$(sha256sum "$vendra_cert" | cut -d ' ' -f 1)
vendra_previous=$(cat /run/vendra/certificate.sha256 2>/dev/null || true)
if [ "$vendra_digest" != "$vendra_previous" ]; then
 /usr/sbin/apache2 -t -f /srv/reporvexo/ops/apache.conf
 /usr/bin/systemctl reload vendra.service
 printf '%s\n' "$vendra_digest" > /run/vendra/certificate.sha256
fi
