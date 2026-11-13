#!/bin/bash
# Incremental work-order overlay; reviewed exceptions to protected PDV paths. PLAN ONLY unless --apply is explicit.
set -Eeuo pipefail
umask 0077
if [[ ${1:-} != --apply ]]; then
 cat <<'PLAN'
PLAN ONLY — work orders, base f106933; no schema/dependency change.
Required: --stage, --manifest, --baseline, --pdv, --manifest-sha256.
No Composer install, migration, seed, PFX or official transmission.
Checks reviewed overlay/base and protected PDV; pauses timer, drains existing
worker naturally (max60s), pauses only Vendra, rechecks empty real outbox, backs
up and overlays only reviewed work-order files. Lints PHP and compiled
Blade, waits for verified HTTPS readiness, then restores prior timer state.
Never invokes the fiscal worker manually. See docs/ORDENS_DE_SERVICO.md.
PLAN
 exit 0
fi
shift
stage= manifest= baseline= pdv= manifest_digest=
while (($#)); do
 case "$1" in
  --stage) stage=${2:?}; shift 2;;
  --manifest) manifest=${2:?}; shift 2;;
  --baseline) baseline=${2:?}; shift 2;;
  --pdv) pdv=${2:?}; shift 2;;
  --manifest-sha256) manifest_digest=${2:?}; shift 2;;
  *) echo 'Argumento não reconhecido.' >&2; exit 1;;
 esac
done
[[ $EUID == 0 && ${VENDRA_WORK_ORDERS_DEPLOY_APPROVED:-} == 1 && ${VENDRA_WORK_ORDERS_QA_PASSED:-} == 1 ]] || { echo 'Requer root, revisão explícita e QA aprovado.' >&2; exit 1; }
[[ $manifest_digest =~ ^[a-f0-9]{64}$ ]] || exit 1
for input in "$stage" "$manifest" "$baseline" "$pdv"; do
 [[ $input == /* && -e $input && ! -L $input ]] || { echo 'Caminho inválido ou symlink.' >&2; exit 1; }
done
live=/srv/reporvexo
php_cli=/opt/reporvexo-tools/php/cli.ini
[[ $(realpath "$stage") == /srv/vendra-work-orders-qa.* && $(realpath "$live") == "$live" ]] || exit 1
[[ $(sha256sum "$manifest" | cut -d ' ' -f1) == "$manifest_digest" ]] || { echo 'Manifesto divergiu da revisão.' >&2; exit 1; }
exec 9>/run/lock/vendra-fiscal-deploy.lock
flock -n 9 || { echo 'Outro deploy coordenado está em andamento.' >&2; exit 1; }
python3 - "$stage" "$live" "$manifest" "$baseline" "$pdv" <<'PY'
import hashlib,pathlib,re,sys
stage,live=map(pathlib.Path,sys.argv[1:3])
allowed={
'app/Http/Controllers/OperationsController.php',
'app/Services/Documents.php','routes/web.php','resources/views/desk.blade.php',
'resources/views/documents/form.blade.php','resources/views/documents/index.blade.php',
'resources/views/documents/show.blade.php','resources/views/components/sales/entry-items.blade.php',
'ops/deploy-work-orders.sh','docs/ORDENS_DE_SERVICO.md',
}
def read(path):
 data={}
 for line in pathlib.Path(path).read_text().splitlines():
  m=re.fullmatch(r'([a-f0-9]{64})  ([A-Za-z0-9_./-]+)',line)
  if not m:raise SystemExit('Formato de manifesto inválido.')
  digest,name=m.groups();p=pathlib.PurePosixPath(name)
  if p.is_absolute() or '..' in p.parts or name in data:raise SystemExit('Caminho de manifesto inválido.')
  data[name]=digest
 if not data:raise SystemExit('Manifesto vazio.')
 return data
def regular(root,name):
 p=root/name
 if not p.is_file() or p.is_symlink() or not p.resolve().is_relative_to(root.resolve()):raise SystemExit('Arquivo inválido: '+name)
 # Check only ancestors of files being touched; no expensive scan of vendor/data.
 for parent in [p.parent,*p.parents]:
  if parent==root:break
  if parent.is_symlink():raise SystemExit('Diretório com symlink: '+name)
 return p
def digest(path):return hashlib.sha256(path.read_bytes()).hexdigest()
overlay,base,pdv=map(read,sys.argv[3:6])
for name,expected in overlay.items():
 if name not in allowed:raise SystemExit('Fora do escopo OS revisado: '+name)
 if digest(regular(stage,name))!=expected:raise SystemExit('Conteúdo mudou após QA: '+name)
 if (live/name).exists() and name not in base:raise SystemExit('Arquivo novo já existe na VPS: '+name)
for name,expected in base.items():
 if name not in overlay:raise SystemExit('Base inclui caminho sem overlay.')
 if digest(regular(live,name))!=expected:raise SystemExit('Base da VPS divergiu: '+name)
if len(pdv)!=101:raise SystemExit('Esperada a relação protegida revisada de101 caminhos.')
for name,expected in pdv.items():
 if name in overlay:
  if name not in allowed or name not in base:raise SystemExit('Exceção OS precisa de caminho liberado e baseline: '+name)
  # Stage must retain the NEW OS implementation; never normalize these paths
  # back to old PDV hashes. Live is checked against its reviewed main baseline.
  if digest(regular(live,name))!=base[name]:raise SystemExit('Arquivo OS protegido divergiu da base revisada: '+name)
  print('Exceção OS explícita, baseline conferido: '+name)
 elif digest(regular(live,name))!=expected or digest(regular(stage,name))!=expected:
  raise SystemExit('Demais caminhos PDV devem permanecer intocados: '+name)
for name in ['composer.json','composer.lock']:
 if digest(regular(stage,name))!=digest(regular(live,name)):raise SystemExit('Dependências mudaram; exige outro plano: '+name)
for folder in ['database/migrations','database/tenant-migrations']:
 a={str(p.relative_to(stage)):digest(regular(stage,str(p.relative_to(stage)))) for p in (stage/folder).glob('*.php')}
 b={str(p.relative_to(live)):digest(regular(live,str(p.relative_to(live)))) for p in (live/folder).glob('*.php')}
 if a!=b:raise SystemExit('Migrações mudaram; este plano não aceita alteração de schema.')
print('Overlay OS, baseline, schema e demais arquivos PDV conferidos.')
PY
[[ -f $php_cli ]] || exit 1
runuser -u vendra-web -- /usr/bin/php -c "$php_cli" -r 'exit(class_exists("SoapClient") ? 0 : 1);'
while read -r digest relative; do
 if [[ $relative == *.php && $relative != *.blade.php ]]; then
  /usr/bin/php -c "$php_cli" -l "$stage/$relative" >/dev/null
 fi
done < "$manifest"
systemctl is-active --quiet vendra.service || { echo 'Vendra já está parado; revisar antes de implantar.' >&2; exit 1; }
wait_https() {
 local attempt
 for ((attempt=0;attempt<10;attempt++)); do
  if curl --resolve 2.25.146.67:8445:127.0.0.1 --connect-timeout 2 --max-time 4 --fail --silent --show-error --output /dev/null https://2.25.146.67:8445/app/vexo/login; then return 0; fi
  sleep 1
 done
 return 1
}
timer_was_active=0
if systemctl is-active --quiet vendra-fiscal.timer; then timer_was_active=1; fi
worker_state=$(systemctl show -p ActiveState --value vendra-fiscal.service)
worker_initial_state=$worker_state
case "$worker_state" in inactive|active|activating) ;; *) echo 'Worker em estado inesperado; revisar antes do deploy.' >&2; exit 1;; esac
live_paused=0
timer_paused=0
overlay_started=0
baseline_recheck_failed=0
succeeded=0
restore_timer() {
 if [[ $timer_was_active == 1 ]]; then systemctl start vendra-fiscal.timer; fi
 # Do not start the oneshot manually: restoring its scheduler preserves behavior.
}
on_exit() {
 code=$?
 if [[ $succeeded != 1 && $timer_paused == 1 ]]; then
  if [[ $overlay_started == 1 || $baseline_recheck_failed == 1 ]]; then
   systemctl stop vendra.service || true
   systemctl stop vendra-fiscal.timer || true
   printf 'Overlay incompleto ou base externa divergente: somente Vendra e seu timer permanecem pausados. Sem rollback automático. Journal: %s\n' "${journal:-não criado}" >&2
  else
   if [[ $live_paused == 1 ]]; then
    if ! systemctl start vendra.service || ! wait_https; then
     echo 'Falha ao restabelecer HTTPS da versão anterior; timer permanece pausado para revisão.' >&2
     exit "$code"
    fi
   fi
   restore_timer || true
   echo 'Falha antes do overlay: estado operacional anterior solicitado novamente; conferir serviços.' >&2
  fi
 fi
 exit "$code"
}
trap on_exit EXIT
systemctl stop vendra-fiscal.timer
timer_paused=1
# Never terminate an in-flight official request. The stopped timer won't schedule
# another batch. Existing batch must finish naturally, otherwise abort untouched.
worker_drained=0
for ((attempt=0;attempt<60;attempt++)); do
 worker_state=$(systemctl show -p ActiveState --value vendra-fiscal.service)
 if [[ $worker_state == inactive ]]; then worker_drained=1; break; fi
 if [[ $worker_state == failed ]]; then echo 'Worker falhou ao drenar; revisar antes de continuar.' >&2; exit 1; fi
 sleep 1
done
[[ $worker_drained == 1 ]] || { echo 'Worker não drenou em60s; nenhum envio foi interrompido.' >&2; exit 1; }
systemctl stop vendra.service
live_paused=1
# Keep original fiscal queue bytes/state, not merely its row count. Nothing here
# allocates fiscal numbers, signs, transmits or processes the queue.
install -d -m700 /var/lib/vendra/work-orders-deploy
journal=$(mktemp -d /var/lib/vendra/work-orders-deploy/review.XXXXXX)
printf 'timer_was_active=%s\nworker_before=%s\nmanifest=%s\n' "$timer_was_active" "$worker_initial_state" "$manifest_digest" > "$journal/review.txt"
cp "$manifest" "$journal/overlay.sha256"
cp "$baseline" "$journal/baseline.sha256"
cp "$pdv" "$journal/pdv-original.sha256"
python3 - "$manifest" "$pdv" "$journal" <<'PY_JOURNAL'
import json,pathlib,sys
def read(path):return {line.split('  ',1)[1]:line.split('  ',1)[0] for line in pathlib.Path(path).read_text().splitlines()}
overlay,pdv=map(read,sys.argv[1:3]);journal=pathlib.Path(sys.argv[3])
remaining={name:digest for name,digest in pdv.items() if name not in overlay}
(journal/'pdv-unchanged.sha256').write_text(''.join(digest+'  '+name+'\n' for name,digest in sorted(remaining.items())))
(journal/'pdv-os-exceptions.json').write_text(json.dumps({name:{'original_pdv_sha256':digest,'reviewed_os_sha256':overlay[name]} for name,digest in pdv.items() if name in overlay},indent=2))
PY_JOURNAL
fiscal_queue_snapshot() {
 runuser -u vendra-web -- env VENDRA_OS_LIVE="$live" /usr/bin/php -c "$php_cli" <<'PHP_QUEUE'
<?php
$root=getenv('VENDRA_OS_LIVE');chdir($root);require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection=app(App\Services\TenantConnection::class);$snapshots=[];
foreach(App\Models\Tenant::orderBy('id')->get() as $tenant){
 try{$context=clone $tenant;$context->active=true;$connection->connect($context);
  $query=Illuminate\Support\Facades\DB::connection('tenant')->table('fiscal_outbox');
  if((clone $query)->whereNull('completed_at')->exists())throw new RuntimeException('Há pendência na outbox real; revisar antes do deploy.');
  $hash=hash_init('sha256');$rows=0;
  foreach((clone $query)->orderBy('id')->cursor() as $row){hash_update($hash,json_encode((array)$row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");$rows++;}
  $snapshots[]=['tenant_id'=>$tenant->id,'rows'=>$rows,'sha256'=>hash_final($hash)];
 }finally{$connection->disconnect();}
}
echo json_encode($snapshots,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
PHP_QUEUE
}
fiscal_queue_snapshot > "$journal/fiscal-outbox.before.json"
VENDRA_BACKUP_ACK_QUIESCED=1 sh "$live/ops/backup.sh" > "$journal/backup-result.txt"
backup_path=$(python3 - "$journal/backup-result.txt" <<'PY_BACKUP'
import pathlib,re,sys
text=pathlib.Path(sys.argv[1]).read_text()
m=re.search(r'^Backup local criado em (/var/backups/vendra/[0-9TZ]+-[A-Za-z0-9]+)\. Ainda requer',text,re.M)
if not m:raise SystemExit('Não foi possível identificar o backup concluído.')
p=pathlib.Path(m.group(1))
if p.is_symlink() or p.resolve().parent!=pathlib.Path('/var/backups/vendra').resolve():raise SystemExit('Diretório de backup inesperado.')
expected={'databases.txt','databases.sql','application.tar.gz'}
entries=set()
for line in (p/'SHA256SUMS').read_text().splitlines():
 match=re.fullmatch(r'[a-f0-9]{64}  ([A-Za-z0-9_.-]+)',line)
 if not match:raise SystemExit('Manifesto de backup inválido.')
 entries.add(match.group(1))
if entries!=expected:raise SystemExit('Backup não contém o conjunto esperado.')
for name in expected:
 f=p/name
 if not f.is_file() or f.is_symlink() or f.stat().st_size==0:raise SystemExit('Arquivo de backup ausente/vazio.')
print(p)
PY_BACKUP
)
(cd "$backup_path" && sha256sum --check --strict SHA256SUMS) > "$journal/backup-integrity.txt"
tar -tzf "$backup_path/application.tar.gz" >/dev/null
# Revalidate immediately before the overlay. The shared lock cannot prevent a
# different chat/tool that ignores it from deploying during the backup window.
if ! (cd "$live" && sha256sum --check "$baseline") > "$journal/baseline-preoverlay.txt"; then
 baseline_recheck_failed=1
 echo 'A base mudou durante a janela; nenhum arquivo OS será sobrescrito. Revisão manual necessária.' >&2
 exit 1
fi
if ! (cd "$live" && sha256sum --check "$journal/pdv-unchanged.sha256") > "$journal/pdv-preoverlay.txt"; then
 baseline_recheck_failed=1
 echo 'Caminho PDV protegido mudou durante o backup; implantação bloqueada.' >&2
 exit 1
fi
if ! python3 - "$live" "$manifest" "$baseline" <<'PY_NEW_PATHS'
import pathlib,sys
def read(path):return {line.split('  ',1)[1] for line in pathlib.Path(path).read_text().splitlines()}
live=pathlib.Path(sys.argv[1]);overlay,base=map(read,sys.argv[2:4])
for name in overlay-base:
 p=live/name
 if p.exists() or p.is_symlink():raise SystemExit('Novo caminho apareceu durante o backup: '+name)
PY_NEW_PATHS
then
 baseline_recheck_failed=1
 exit 1
fi
overlay_started=1
while read -r digest relative; do
 destination="$live/$relative"
 parent=$(dirname "$destination")
 install -D -m640 -o root -g vendra-web "$stage/$relative" "$destination"
 while [[ $parent != "$live" ]]; do
  chown root:vendra-web "$parent"
  chmod 750 "$parent"
  parent=$(dirname "$parent")
 done
done < "$manifest"
cd "$live"
runuser -u vendra-web -- /usr/bin/php -c "$php_cli" artisan config:clear
runuser -u vendra-web -- /usr/bin/php -c "$php_cli" artisan route:clear
runuser -u vendra-web -- /usr/bin/php -c "$php_cli" artisan view:clear
# No migrations, seeds or Composer: existing OS JSON/metadata/schema are reused.
runuser -u vendra-web -- /usr/bin/php -c "$php_cli" artisan view:cache
while IFS= read -r -d '' compiled; do
 /usr/bin/php -c "$php_cli" -l "$compiled" >/dev/null
done < <(find storage/framework/views -type f -name '*.php' -print0)
# Touch only generated caches. Preserve private fiscal data/PFX/XML permissions
# and all commercial files, storage, vendor and service/INI configuration.
for generated in storage/framework/views bootstrap/cache; do
 chown -R vendra-web:vendra-web "$generated"
 find "$generated" -type d -exec chmod 750 {} +
 find "$generated" -type f -exec chmod 640 {} +
done
sha256sum --check "$journal/pdv-unchanged.sha256" > "$journal/pdv-after.txt"
sha256sum --check "$manifest" > "$journal/overlay-after.txt"
fiscal_queue_snapshot > "$journal/fiscal-outbox.after.json"
cmp "$journal/fiscal-outbox.before.json" "$journal/fiscal-outbox.after.json"
apache2 -t -f /srv/reporvexo/ops/apache.conf
systemctl start vendra.service
wait_https || { echo 'HTTPS não ficou pronto; worker/timer não serão retomados sobre release incerta.' >&2; exit 1; }
restore_timer
if [[ $timer_was_active == 1 ]]; then systemctl is-active --quiet vendra-fiscal.timer; fi
printf '%s\n' 'complete-prior-scheduler-restored' > "$journal/status"
succeeded=1
printf 'Overlay OS aplicado; fila fiscal e demais arquivos PDV preservados; HTTPS pronto, agendamento anterior restaurado. Journal: %s\n' "$journal"
