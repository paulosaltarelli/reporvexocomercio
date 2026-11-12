#!/bin/bash
# Incremental reusable fiscal-tax-profile overlay. PLAN ONLY unless --apply is explicit.
set -Eeuo pipefail
umask 0077
if [[ ${1:-} != --apply ]]; then
 cat <<'PLAN'
PLAN ONLY — reusable fiscal tax profiles, base b5b8154.
Required: --stage, --manifest, --baseline, --pdv, --manifest-sha256.
No Composer install, old/central migration, seed, PFX or official transmission.
Checks reviewed overlay/base and protected PDV; pauses timer, drains existing
worker naturally (max60s), pauses only Vendra, rechecks empty real outbox, backs
up and applies only tenant migration000007. Lints PHP and compiled
Blade, waits for verified HTTPS readiness, then restores prior timer state.
Never invokes the fiscal worker manually. See docs/PERFIS_TRIBUTARIOS.md.
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
[[ $EUID == 0 && ${VENDRA_FISCAL_PROFILES_DEPLOY_APPROVED:-} == 1 && ${VENDRA_FISCAL_PROFILES_QA_PASSED:-} == 1 ]] || { echo 'Requer root, revisão explícita e QA aprovado.' >&2; exit 1; }
[[ $manifest_digest =~ ^[a-f0-9]{64}$ ]] || exit 1
for input in "$stage" "$manifest" "$baseline" "$pdv"; do
 [[ $input == /* && -e $input && ! -L $input ]] || { echo 'Caminho inválido ou symlink.' >&2; exit 1; }
done
live=/srv/reporvexo
php_cli=/opt/reporvexo-tools/php/cli.ini
[[ $(realpath "$stage") == /srv/vendra-fiscal-profile-qa.* && $(realpath "$live") == "$live" ]] || exit 1
[[ $(sha256sum "$manifest" | cut -d ' ' -f1) == "$manifest_digest" ]] || { echo 'Manifesto divergiu da revisão.' >&2; exit 1; }
exec 9>/run/lock/vendra-fiscal-deploy.lock
flock -n 9 || { echo 'Outro deploy fiscal está em andamento.' >&2; exit 1; }
python3 - "$stage" "$live" "$manifest" "$baseline" "$pdv" <<'PY'
import hashlib,pathlib,re,sys
stage,live=map(pathlib.Path,sys.argv[1:3])
allowed={
'app/Fiscal/FiscalProfileManager.php','app/Fiscal/FiscalRuleResolver.php',
'app/Fiscal/NfcePayload.php','app/Fiscal/FiscalReadiness.php','app/Fiscal/TaxProfile.php',
'app/Http/Controllers/FiscalProfileController.php','app/Http/Controllers/FiscalEmissionController.php',
'app/Models/FiscalTaxProfile.php','app/Models/FiscalTaxProfileAudit.php','routes/web.php',
'resources/views/fiscal/index.blade.php','resources/views/fiscal/product.blade.php',
'resources/views/fiscal/parameter-fields.blade.php',
'resources/views/fiscal/profiles/index.blade.php','resources/views/fiscal/profiles/form.blade.php',
'resources/views/fiscal/profiles/bulk.blade.php',
'database/tenant-migrations/2026_11_12_000007_create_fiscal_tax_profiles.php',
'ops/deploy-fiscal-profiles.sh','docs/PERFIS_TRIBUTARIOS.md',
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
 if name not in allowed:raise SystemExit('Fora do escopo de perfis tributários: '+name)
 if digest(regular(stage,name))!=expected:raise SystemExit('Conteúdo mudou após QA: '+name)
 if (live/name).exists() and name not in base:raise SystemExit('Arquivo novo já existe na VPS: '+name)
for name,expected in base.items():
 if name not in overlay:raise SystemExit('Base inclui caminho sem overlay.')
 if digest(regular(live,name))!=expected:raise SystemExit('Base da VPS divergiu: '+name)
for name,expected in pdv.items():
 if name in overlay:raise SystemExit('PDV protegido não pode estar no overlay: '+name)
 if digest(regular(live,name))!=expected or digest(regular(stage,name))!=expected:raise SystemExit('PDV protegido divergiu: '+name)
for name in ['composer.json','composer.lock']:
 if digest(regular(stage,name))!=digest(regular(live,name)):raise SystemExit('Dependências mudaram; exige outro plano: '+name)
print('Overlay incremental, dependências e PDV conferidos.')
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
succeeded=0
restore_timer() {
 if [[ $timer_was_active == 1 ]]; then systemctl start vendra-fiscal.timer; fi
 # Do not start the oneshot manually: restoring its scheduler preserves behavior.
}
on_exit() {
 code=$?
 if [[ $succeeded != 1 && $timer_paused == 1 ]]; then
  if [[ $overlay_started == 1 ]]; then
   systemctl stop vendra.service || true
   systemctl stop vendra-fiscal.timer || true
   printf 'Overlay incompleto: somente Vendra e seu timer permanecem pausados. Sem rollback automático. Journal: %s\n' "${journal:-não criado}" >&2
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
# Read-only check after HTTP writes and worker are paused. Profile changes never
# create entries in the real fiscal queue or update tax rates automatically.
runuser -u vendra-web -- env VENDRA_PROFILES_LIVE="$live" /usr/bin/php -c "$php_cli" <<'PHP'
<?php
$root=getenv('VENDRA_PROFILES_LIVE');chdir($root);require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection=app(App\Services\TenantConnection::class);
foreach(App\Models\Tenant::orderBy('id')->get() as $tenant){
 try{$context=clone $tenant;$context->active=true;$connection->connect($context);
  if(Illuminate\Support\Facades\DB::connection('tenant')->table('fiscal_outbox')->whereNull('completed_at')->exists())throw new RuntimeException('Há pendência na outbox real; revisar antes do deploy.');
 }finally{$connection->disconnect();}
}
echo 'Outbox real vazia em todas as bases retidas.'.PHP_EOL;
PHP
install -d -m700 /var/lib/vendra/fiscal-profiles-deploy
journal=$(mktemp -d /var/lib/vendra/fiscal-profiles-deploy/review.XXXXXX)
printf 'timer_was_active=%s\nworker_before=%s\nmanifest=%s\n' "$timer_was_active" "$worker_initial_state" "$manifest_digest" > "$journal/review.txt"
cp "$manifest" "$journal/overlay.sha256"
cp "$baseline" "$journal/baseline.sha256"
cp "$pdv" "$journal/pdv.sha256"
VENDRA_BACKUP_ACK_QUIESCED=1 sh "$live/ops/backup.sh" > "$journal/backup-result.txt"
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
# No central/old/commercial migration: only nullable fiscal links and new profile/audit tables.
runuser -u vendra-web -- env VENDRA_PROFILES_LIVE="$live" /usr/bin/php -c "$php_cli" <<'PHP'
<?php
$root=getenv('VENDRA_PROFILES_LIVE');chdir($root);require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$paths=['database/tenant-migrations/2026_11_12_000007_create_fiscal_tax_profiles.php'];
$connection=app(App\Services\TenantConnection::class);
foreach(App\Models\Tenant::orderBy('id')->get() as $tenant){
 try{$context=clone $tenant;$context->active=true;$connection->connect($context);
  foreach($paths as $path){if(Illuminate\Support\Facades\Artisan::call('migrate',['--database'=>'tenant','--path'=>$path,'--force'=>true])!==0)throw new RuntimeException('Migração incremental fiscal falhou.');}
  echo 'Estrutura de perfis tributários: empresa #'.$tenant->id.PHP_EOL;
 }finally{$connection->disconnect();}
}
PHP
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
sha256sum --check "$pdv" > "$journal/pdv-after.txt"
apache2 -t -f /srv/reporvexo/ops/apache.conf
systemctl start vendra.service
wait_https || { echo 'HTTPS não ficou pronto; worker/timer não serão retomados sobre release incerta.' >&2; exit 1; }
restore_timer
if [[ $timer_was_active == 1 ]]; then systemctl is-active --quiet vendra-fiscal.timer; fi
printf '%s\n' 'complete-prior-scheduler-restored' > "$journal/status"
succeeded=1
printf 'Overlay de perfis tributários aplicado; HTTPS pronto, agendamento anterior restaurado. Journal: %s\n' "$journal"
