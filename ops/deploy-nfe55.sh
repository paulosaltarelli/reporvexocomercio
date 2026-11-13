#!/bin/bash
# Incremental NF-e55 overlay; exact reviewed scope, post-OS baseline required. PLAN ONLY unless --apply is explicit.
set -Eeuo pipefail
umask 0077
if [[ ${1:-} != --apply ]]; then
 cat <<'PLAN'
PLAN ONLY — NF-e55 alongside NFC-e65; reviewed post-OS baseline required.
Required: --stage, --manifest, --baseline, --pdv, --manifest-sha256.
No Composer install, old/central migration, seed, PFX or official transmission.
Checks reviewed overlay/base and protected PDV; pauses timer, drains existing
worker naturally (max60s), pauses only Vendra, rechecks empty real outbox, backs
up and applies only the new NF-e55 tenant migration after final scope freeze. Lints PHP and compiled
Blade, waits for verified HTTPS readiness, then restores prior timer state.
Never invokes the fiscal worker manually. See ops/deploy-nfe55.sh.
PLAN
 exit 0
fi
shift
# Backend/UX file scope frozen; root must still merge and QA against post-OS main.
freeze_ready=1
[[ $freeze_ready == 1 ]] || { echo 'Allowlist final DBA/UX e baseline pós-OS ainda não congelados.' >&2; exit 1; }
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
[[ $EUID == 0 && ${VENDRA_NFE55_DEPLOY_APPROVED:-} == 1 && ${VENDRA_NFE55_QA_PASSED:-} == 1 ]] || { echo 'Requer root, revisão explícita e QA aprovado.' >&2; exit 1; }
[[ $manifest_digest =~ ^[a-f0-9]{64}$ ]] || exit 1
for input in "$stage" "$manifest" "$baseline" "$pdv"; do
 [[ $input == /* && -e $input && ! -L $input ]] || { echo 'Caminho inválido ou symlink.' >&2; exit 1; }
done
live=/srv/reporvexo
php_cli=/opt/reporvexo-tools/php/cli.ini
[[ $(realpath "$stage") == /srv/vendra-nfe55-qa.* && $(realpath "$live") == "$live" ]] || exit 1
[[ $(sha256sum "$manifest" | cut -d ' ' -f1) == "$manifest_digest" ]] || { echo 'Manifesto divergiu da revisão.' >&2; exit 1; }
exec 9>/run/lock/vendra-fiscal-deploy.lock
flock -n 9 || { echo 'Outro deploy fiscal está em andamento.' >&2; exit 1; }
python3 - "$stage" "$live" "$manifest" "$baseline" "$pdv" <<'PY'
import hashlib,pathlib,re,sys
stage,live=map(pathlib.Path,sys.argv[1:3])
allowed={
'app/Fiscal/NfeCompanyConfiguration.php','app/Fiscal/NfeRecipient.php',
'app/Fiscal/NfePayload.php','app/Fiscal/NfeXmlBuilder.php','app/Fiscal/QueueNfe.php',
'app/Http/Controllers/NfeEmissionController.php','app/Models/FiscalModelConfiguration.php',
'database/tenant-migrations/2026_11_13_000008_add_nfe_homologation.php',
'app/Fiscal/NfcePayload.php','app/Fiscal/ProcessFiscalOutbox.php','app/Fiscal/QueueNfce.php',
'app/Fiscal/SefazProvider.php','app/Fiscal/SefazTransport.php','app/Fiscal/XmlEvidence.php',
'app/Http/Controllers/BusinessController.php','app/Http/Controllers/FiscalController.php',
'app/Http/Controllers/FiscalEmissionController.php','app/Models/FiscalDocument.php','routes/web.php',
'resources/views/fiscal/company.blade.php','resources/views/fiscal/index.blade.php',
'resources/views/fiscal/sale.blade.php','resources/views/fiscal/nfe/company.blade.php',
'resources/views/fiscal/nfe/customer.blade.php','resources/views/fiscal/nfe/document.blade.php',
'docs/PRIMEIRA_HOMOLOGACAO.md','docs/NFSE_ARAGUARI.md','ops/deploy-nfe55.sh',
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
 if name not in allowed:raise SystemExit('Fora do escopo NF-e55 revisado: '+name)
 if digest(regular(stage,name))!=expected:raise SystemExit('Conteúdo mudou após QA: '+name)
 if (live/name).exists() and name not in base:raise SystemExit('Arquivo novo já existe na VPS: '+name)
for name,expected in base.items():
 if name not in overlay:raise SystemExit('Base inclui caminho sem overlay.')
 if digest(regular(live,name))!=expected:raise SystemExit('Base da VPS divergiu: '+name)
if len(pdv)!=101:raise SystemExit('Esperada relação pós-OS revisada com101 arquivos protegidos.')
for name,expected in pdv.items():
 if name in overlay:raise SystemExit('PDV protegido não pode estar no overlay: '+name)
 if digest(regular(live,name))!=expected or digest(regular(stage,name))!=expected:raise SystemExit('PDV pós-OS protegido divergiu: '+name)
for name in ['composer.json','composer.lock']:
 if digest(regular(stage,name))!=digest(regular(live,name)):raise SystemExit('Dependências mudaram; exige outro plano: '+name)
print('Overlay NF-e55, dependências e PDV pós-OS conferidos.')
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
 # These two files contain only helper code and hashed projection metadata.
 if [[ ${reader_dir:-} == /run/vendra-nfe55-deploy.* && -d ${reader_dir:-} && ! -L ${reader_dir:-} ]]; then
  rm -f "$reader_dir/snapshot.php" "$reader_dir/projection.json"
  rmdir "$reader_dir" || true
 fi
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
# Snapshot only raw database values; hashes/column metadata, never credentials or
# customer rows, are written to the private journal. Transport is not invoked.
install -d -m700 /var/lib/vendra/nfe55-deploy
journal=$(mktemp -d /var/lib/vendra/nfe55-deploy/review.XXXXXX)
printf 'timer_was_active=%s\nworker_before=%s\nmanifest=%s\n' "$timer_was_active" "$worker_initial_state" "$manifest_digest" > "$journal/review.txt"
cp "$manifest" "$journal/overlay.sha256"
cp "$baseline" "$journal/baseline.sha256"
cp "$pdv" "$journal/pdv.sha256"
reader_dir=$(mktemp -d /run/vendra-nfe55-deploy.XXXXXX)
chown root:vendra-web "$reader_dir"
chmod 750 "$reader_dir"
cat > "$reader_dir/snapshot.php" <<'PHP_SNAPSHOT'
<?php
$root=getenv('VENDRA_NFE55_LIVE');chdir($root);require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$phase=$argv[1]??'';
if(!in_array($phase,['before','after'],true))throw new RuntimeException('Invalid snapshot phase.');
$expected=$phase==='after'?json_decode(file_get_contents(__DIR__.'/projection.json'),true,512,JSON_THROW_ON_ERROR):null;
function captureTable($db,string $name,?array $expected):array{
 $actual=$db->getSchemaBuilder()->getColumnListing($name);
 $columns=$expected['columns']??$actual;
 if(!in_array('id',$columns,true)||array_diff($columns,$actual))throw new RuntimeException('Original fiscal columns missing.');
 $hash=hash_init('sha256');$rows=0;
 foreach($db->table($name)->select($columns)->orderBy('id')->cursor() as $row){
  hash_update($hash,json_encode((array)$row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");$rows++;
 }
 return ['columns'=>$columns,'rows'=>$rows,'sha256'=>hash_final($hash)];
}
$result=['central'=>captureTable(Illuminate\Support\Facades\DB::connection('central'),'tenants',$expected['central']??null),'tenants'=>[]];
$connection=app(App\Services\TenantConnection::class);
$customerColumns=['fiscal_street','fiscal_number','fiscal_complement','fiscal_district','fiscal_city','fiscal_state','fiscal_postal_code','fiscal_municipality_code','fiscal_ie_indicator','fiscal_state_registration'];
$tables=['customers','fiscal_issuers','fiscal_credentials','fiscal_settings','fiscal_sequences','fiscal_documents','fiscal_outbox','fiscal_events','fiscal_voids','fiscal_files'];
foreach(App\Models\Tenant::orderBy('id')->get() as $tenant){
 try{
  $context=clone $tenant;$context->active=true;$connection->connect($context);
  $db=Illuminate\Support\Facades\DB::connection('tenant');$schema=$db->getSchemaBuilder();
  if($db->table('fiscal_outbox')->whereNull('completed_at')->exists())throw new RuntimeException('Há pendência na outbox real; revisar antes do deploy.');
  $hasModelTable=$schema->hasTable('fiscal_model_configurations');
  $actualCustomer=$schema->getColumnListing('customers');
  if($phase==='before'){
   if($hasModelTable||array_intersect($customerColumns,$actualCustomer))throw new RuntimeException('Estrutura NF-e55 já existe ou está parcial; exige revisão antes de aplicar.');
  }else{
   if(!$hasModelTable||$db->table('fiscal_model_configurations')->exists())throw new RuntimeException('Nova configuração por modelo não permaneceu vazia.');
   if(array_diff($customerColumns,$actualCustomer))throw new RuntimeException('Colunas NF-e55 de destinatário ausentes.');
   if($db->table('customers')->where(function($q)use($customerColumns){foreach($customerColumns as $column)$q->orWhereNotNull($column);})->exists())throw new RuntimeException('Migração preencheu dados fiscais de destinatário sem ação explícita.');
  }
  if($phase==='after'&&!isset($expected['tenants'][(string)$tenant->id]))throw new RuntimeException('Conjunto de empresas mudou durante a manutenção.');
  $snap=[];
  foreach($tables as $table)$snap[$table]=captureTable($db,$table,$expected['tenants'][(string)$tenant->id][$table]??null);
  $result['tenants'][(string)$tenant->id]=$snap;
 }finally{$connection->disconnect();}
}
echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
PHP_SNAPSHOT
chown root:vendra-web "$reader_dir/snapshot.php"
chmod 640 "$reader_dir/snapshot.php"
runuser -u vendra-web -- env VENDRA_NFE55_LIVE="$live" /usr/bin/php -c "$php_cli" "$reader_dir/snapshot.php" before > "$journal/fiscal.before.json"
install -m640 -o root -g vendra-web "$journal/fiscal.before.json" "$reader_dir/projection.json"
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
# The shared lock does not protect against a non-cooperating chat/deployer.
if ! (cd "$live" && sha256sum --check "$baseline") > "$journal/baseline-preoverlay.txt"; then
 baseline_recheck_failed=1;echo 'Base mudou durante o backup; implantação interrompida.' >&2;exit 1
fi
if ! (cd "$live" && sha256sum --check "$pdv") > "$journal/pdv-preoverlay.txt"; then
 baseline_recheck_failed=1;echo 'PDV pós-OS mudou durante o backup; implantação interrompida.' >&2;exit 1
fi
if ! python3 - "$live" "$manifest" "$baseline" <<'PY_NEW_PATHS'
import pathlib,sys
def read(path):return {line.split('  ',1)[1] for line in pathlib.Path(path).read_text().splitlines()}
live=pathlib.Path(sys.argv[1]);overlay,base=map(read,sys.argv[2:4])
for name in overlay-base:
 p=live/name
 if p.exists() or p.is_symlink():raise SystemExit('Novo caminho apareceu durante a janela: '+name)
PY_NEW_PATHS
then baseline_recheck_failed=1;exit 1;fi
overlay_started=1
while read -r digest relative; do
 destination="$live/$relative"
 parent=$(dirname "$destination")
 install -D -m640 -o root -g vendra-web "$stage/$relative" "$destination"
 # Keep new NF55 code/view directories readable under deployment umask0077.
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
# No central/old/commercial migration: only the new model configuration and nullable recipient fields.
runuser -u vendra-web -- env VENDRA_NFE55_LIVE="$live" /usr/bin/php -c "$php_cli" <<'PHP'
<?php
$root=getenv('VENDRA_NFE55_LIVE');chdir($root);require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$paths=['database/tenant-migrations/2026_11_13_000008_add_nfe_homologation.php'];
$connection=app(App\Services\TenantConnection::class);
foreach(App\Models\Tenant::orderBy('id')->get() as $tenant){
 try{$context=clone $tenant;$context->active=true;$connection->connect($context);
  foreach($paths as $path){if(Illuminate\Support\Facades\Artisan::call('migrate',['--database'=>'tenant','--path'=>$path,'--force'=>true])!==0)throw new RuntimeException('Migração incremental fiscal falhou.');}
  echo 'Estrutura NF-e55: empresa #'.$tenant->id.PHP_EOL;
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
sha256sum --check "$manifest" > "$journal/overlay-after.txt"
runuser -u vendra-web -- env VENDRA_NFE55_LIVE="$live" /usr/bin/php -c "$php_cli" "$reader_dir/snapshot.php" after > "$journal/fiscal.after.json"
cmp "$journal/fiscal.before.json" "$journal/fiscal.after.json"
apache2 -t -f /srv/reporvexo/ops/apache.conf
systemctl start vendra.service
wait_https || { echo 'HTTPS não ficou pronto; worker/timer não serão retomados sobre release incerta.' >&2; exit 1; }
restore_timer
if [[ $timer_was_active == 1 ]]; then systemctl is-active --quiet vendra-fiscal.timer; fi
printf '%s\n' 'complete-prior-scheduler-restored' > "$journal/status"
succeeded=1
printf 'Overlay NF-e55 aplicado; modelos/documentos/numerações anteriores preservados; HTTPS pronto, agendamento anterior restaurado. Journal: %s\n' "$journal"
