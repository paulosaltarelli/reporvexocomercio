#!/usr/bin/env bash
# Import only into a new offline container. No host mounts, ports or database credentials.
set -euo pipefail
snapshot=${1:?Informe o diretório recém-restaurado}
image=${2:?Informe a imagem MySQL já presente e compatível com o backup}
[[ -f "$snapshot/databases.sql" && -s "$snapshot/databases.txt" ]]
mapfile -t databases < "$snapshot/databases.txt"
[[ "${databases[0]}" == vendra_central ]]
for db in "${databases[@]}"; do
    [[ "$db" =~ ^(vendra_central|vendra_(shared_|t_)[a-z0-9_]+)$ ]]
done
# The official image initializes through a temporary server. Wait for the final mysqld PID 1.
container=$(docker create --pull=never --network none --env MYSQL_ALLOW_EMPTY_PASSWORD=yes "$image" --skip-networking --event-scheduler=OFF)
trap 'docker rm -fv "$container" >/dev/null' EXIT
docker start "$container" >/dev/null
for ((i=0; i<120; i++)); do
    if docker exec "$container" sh -c 'test "$(cat /proc/1/comm)" = mysqld && mysqladmin --protocol=socket -uroot ping' >/dev/null 2>&1; then
        break
    fi
    sleep 1
done
docker exec "$container" sh -c 'test "$(cat /proc/1/comm)" = mysqld'
docker exec -i "$container" mysql --protocol=socket -uroot < "$snapshot/databases.sql"
actual=$(docker exec "$container" mysql -NB -uroot --execute="SELECT DISTINCT db_name FROM vendra_central.tenants ORDER BY db_name")
expected=$(tail -n +2 "$snapshot/databases.txt" | LC_ALL=C sort -u)
[[ "$actual" == "$expected" ]]
for db in "${databases[@]}"; do
    count=$(docker exec "$container" mysql -NB -uroot --execute="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$db'")
    [[ "$count" =~ ^[1-9][0-9]*$ ]]
done
echo "Importação SQL validada em MySQL descartável. Bancos conferidos: ${#databases[@]}."
