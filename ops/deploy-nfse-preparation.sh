#!/bin/bash
# Incremental national NFS-e LOCAL PREPARATION overlay; no transport. PLAN ONLY unless --apply is explicit.
set -Eeuo pipefail
umask 0077
if [[ ${1:-} != --apply ]]; then
 cat <<'PLAN'
PLAN ONLY — national NFS-e LOCAL preparation; reviewed post-55+OS baseline required.
Required: --stage, --manifest, --baseline, --pdv, --manifest-sha256.
No Composer install, old/central migration, seed, PFX, national HTTP or transmission.
Checks reviewed overlay/base and protected PDV; pauses timer, drains existing
worker naturally (max60s), pauses only Vendra, rechecks empty real outbox, backs
up and applies only the new NFS-e tenant migration000009. Lints PHP and compiled
Blade, waits for verified HTTPS readiness, then restores prior timer state.
Never invokes the fiscal worker manually. No national outbox/worker is installed. See ops/deploy-nfse-preparation.sh.
PLAN
 exit 0
fi
shift
# Backend/UX LOCAL preparation scope frozen; root must merge and QA post-55+OS views/routes.
freeze_ready=1
[[ $freeze_ready == 1 ]] || { echo 'Allowlist e baseline pós-55+OS precisam estar revisados.' >&2; exit 1; }
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
[[ $EUID == 0 && ${VENDRA_NFSE_PREPARATION_DEPLOY_APPROVED:-} == 1 && ${VENDRA_NFSE_PREPARATION_QA_PASSED:-} == 1 ]] || { echo 'Requer root, revisão explícita e QA aprovado.' >&2; exit 1; }
[[ $manifest_digest =~ ^[a-f0-9]{64}$ ]] || exit 1
for input in "$stage" "$manifest" "$baseline" "$pdv"; do
 [[ $input == /* && -e $input && ! -L $input ]] || { echo 'Caminho inválido ou symlink.' >&2; exit 1; }
done
live=/srv/reporvexo
php_cli=/opt/reporvexo-tools/php/cli.ini
[[ $(realpath "$stage") == /srv/vendra-nfse-qa.* && $(realpath "$live") == "$live" ]] || exit 1
[[ $(sha256sum "$manifest" | cut -d ' ' -f1) == "$manifest_digest" ]] || { echo 'Manifesto divergiu da revisão.' >&2; exit 1; }
exec 9>/run/lock/vendra-fiscal-deploy.lock
flock -n 9 || { echo 'Outro deploy fiscal está em andamento.' >&2; exit 1; }
python3 - "$stage" "$live" "$manifest" "$baseline" "$pdv" <<'PY'
import hashlib,pathlib,re,sys
stage,live=map(pathlib.Path,sys.argv[1:3])
allowed={
'app/Fiscal/Nfse/Access.php',
'app/Fiscal/Nfse/Configuration.php',
'app/Fiscal/Nfse/DpsBuilder.php',
'app/Fiscal/Nfse/PrepareDps.php',
'app/Fiscal/Nfse/Readiness.php',
'app/Fiscal/Nfse/schemas/CNC_v1.00.xsd',
'app/Fiscal/Nfse/schemas/DPS_v1.01.xsd',
'app/Fiscal/Nfse/schemas/NFSe_v1.01.xsd',
'app/Fiscal/Nfse/schemas/README.md',
'app/Fiscal/Nfse/schemas/SHA256SUMS',
'app/Fiscal/Nfse/schemas/evento_v1.01.xsd',
'app/Fiscal/Nfse/schemas/pedRegEvento_v1.01.xsd',
'app/Fiscal/Nfse/schemas/tiposCnc_v1.00.xsd',
'app/Fiscal/Nfse/schemas/tiposComplexos_v1.01.xsd',
'app/Fiscal/Nfse/schemas/tiposEventos_v1.01.xsd',
'app/Fiscal/Nfse/schemas/tiposSimples_v1.01.xsd',
'app/Fiscal/Nfse/schemas/xmldsig-core-schema.xsd',
'app/Http/Controllers/NfseController.php',
'app/Models/NfseConfiguration.php',
'app/Models/NfseDocument.php',
'database/tenant-migrations/2026_11_12_000009_create_national_nfse.php',
'routes/web.php',
'resources/views/fiscal/company.blade.php',
'resources/views/fiscal/index.blade.php',
'resources/views/fiscal/sale.blade.php',
'resources/views/fiscal/nfse/company.blade.php',
'resources/views/fiscal/nfse/entry.blade.php',
'resources/views/fiscal/nfse/index.blade.php',
'resources/views/fiscal/nfse/sale.blade.php',
'resources/views/fiscal/nfse/show.blade.php',
'ops/deploy-nfse-preparation.sh',
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
 if name not in allowed:raise SystemExit('Fora do escopo de preparação LOCAL NFS-e: '+name)
 if digest(regular(stage,name))!=expected:raise SystemExit('Conteúdo mudou após QA: '+name)
 if (live/name).exists() and name not in base:raise SystemExit('Arquivo novo já existe na VPS: '+name)
for name,expected in base.items():
 if name not in overlay:raise SystemExit('Base inclui caminho sem overlay.')
 if digest(regular(live,name))!=expected:raise SystemExit('Base da VPS divergiu: '+name)
if len(pdv)!=101:raise SystemExit('Esperada relação pós-OS revisada com101 arquivos protegidos.')
for name,expected in pdv.items():
 if name in overlay:raise SystemExit('PDV protegido não pode estar no overlay: '+name)
 if digest(regular(live,name))!=expected or digest(regular(stage,name))!=expected:raise SystemExit('PDV pós-55+OS protegido divergiu: '+name)
for name in ['composer.json','composer.lock']:
 if digest(regular(stage,name))!=digest(regular(live,name)):raise SystemExit('Dependências mudaram; exige outro plano: '+name)
schema_dir='app/Fiscal/Nfse/schemas/'
expected_schemas={path[len(schema_dir):] for path in allowed if path.startswith(schema_dir) and path.endswith('.xsd')}
schema_hashes=read(stage/(schema_dir+'SHA256SUMS'))
if set(schema_hashes)!=expected_schemas:raise SystemExit('Manifesto XSD não corresponde ao conjunto local revisado.')
for name,expected in schema_hashes.items():
 if digest(regular(stage,schema_dir+name))!=expected:raise SystemExit('Checksum XSD local divergiu: '+name)
print('Overlay NFS-e LOCAL, dependências, XSD e PDV pós-55+OS conferidos.')
PY
[[ -f $php_cli ]] || exit 1
runuser -u vendra-web -- /usr/bin/php -c "$php_cli" -r 'exit(class_exists("SoapClient") ? 0 : 1);'
while read -r digest relative; do
 if [[ $relative == *.php && $relative != *.blade.php ]]; then
  /usr/bin/php -c "$php_cli" -l "$stage/$relative" >/dev/null
 fi
done < "$manifest"
(cd "$stage/app/Fiscal/Nfse/schemas" && sha256sum --check --strict SHA256SUMS)
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
 if [[ ${reader_dir:-} == /run/vendra-nfse-preparation-deploy.* && -d ${reader_dir:-} && ! -L ${reader_dir:-} ]]; then
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
install -d -m700 /var/lib/vendra/nfse-preparation-deploy
journal=$(mktemp -d /var/lib/vendra/nfse-preparation-deploy/review.XXXXXX)
printf 'timer_was_active=%s\nworker_before=%s\nmanifest=%s\n' "$timer_was_active" "$worker_initial_state" "$manifest_digest" > "$journal/review.txt"
cp "$manifest" "$journal/overlay.sha256"
cp "$baseline" "$journal/baseline.sha256"
cp "$pdv" "$journal/pdv.sha256"
reader_dir=$(mktemp -d /run/vendra-nfse-preparation-deploy.XXXXXX)
chown root:vendra-web "$reader_dir"
chmod 750 "$reader_dir"
cat > "$reader_dir/snapshot.php" <<'PHP_SNAPSHOT'
<?php
$root=getenv('VENDRA_NFSE_PREPARATION_LIVE');chdir($root);require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$phase=$argv[1]??'';
if(!in_array($phase,['before','after'],true))throw new RuntimeException('Invalid snapshot phase.');
$expected=$phase==='after'?json_decode(file_get_contents(__DIR__.'/projection.json'),true,512,JSON_THROW_ON_ERROR):null;
$newMigration='2026_11_12_000009_create_national_nfse';
$newTables=['nfse_configurations','nfse_documents','nfse_sequences'];
function allTables($db):array{
 $tables=$db->table('information_schema.tables')->where('table_schema',$db->getDatabaseName())->where('table_type','BASE TABLE')->orderBy('TABLE_NAME')->pluck('TABLE_NAME')->all();
 foreach($tables as $table){if(!preg_match('/^[a-z0-9_]+$/D',$table))throw new RuntimeException('Unexpected table identifier.');}
 return $tables;
}
function captureTable($db,string $name,?array $expected,bool $tenant,string $newMigration):array{
 $columns=$db->getSchemaBuilder()->getColumnListing($name);
 if($expected&&$columns!==$expected['columns'])throw new RuntimeException('Schema anterior foi alterado.');
 $primary=[];
 foreach($db->getSchemaBuilder()->getIndexes($name) as $index){if($index['primary']??false){$primary=$index['columns'];break;}}
 if(!$primary)throw new RuntimeException('Tabela sem chave primária: snapshot determinístico exige revisão.');
 if($expected&&$primary!==$expected['primary'])throw new RuntimeException('Chave primária anterior foi alterada.');
 $query=$db->table($name)->select($columns);
 if($tenant&&$name==='migrations')$query->where('migration','<>',$newMigration);
 foreach($primary as $column)$query->orderBy($column);
 $hash=hash_init('sha256');$rows=0;
 foreach($query->cursor() as $row){hash_update($hash,json_encode((array)$row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");$rows++;}
 return ['columns'=>$columns,'primary'=>$primary,'rows'=>$rows,'sha256'=>hash_final($hash)];
}
function captureDatabase($db,?array $expected,bool $tenant,string $newMigration,array $newTables,string $phase):array{
 $tables=allTables($db);
 if($tenant){
  foreach($newTables as $table){
   if($phase==='before'&&in_array($table,$tables,true))throw new RuntimeException('Estrutura NFS-e já existe ou está parcial; revisar.');
   if($phase==='after'&&(!in_array($table,$tables,true)||$db->table($table)->exists()))throw new RuntimeException('Nova tabela NFS-e ausente ou não permaneceu vazia.');
  }
  $count=$db->table('migrations')->where('migration',$newMigration)->count();
  if(($phase==='before'&&$count!==0)||($phase==='after'&&$count!==1))throw new RuntimeException('Registro da única migração nova inesperado.');
  $tables=array_values(array_diff($tables,$newTables));
 }
 if($expected&&$tables!==array_keys($expected))throw new RuntimeException('Conjunto de tabelas anteriores mudou.');
 $snapshot=[];
 foreach($tables as $table)$snapshot[$table]=captureTable($db,$table,$expected[$table]??null,$tenant,$newMigration);
 return $snapshot;
}
$result=['central'=>captureDatabase(Illuminate\Support\Facades\DB::connection('central'),$expected['central']??null,false,$newMigration,[],$phase),'tenants'=>[]];
$connection=app(App\Services\TenantConnection::class);
$customerColumns=['fiscal_street','fiscal_number','fiscal_complement','fiscal_district','fiscal_city','fiscal_state','fiscal_postal_code','fiscal_municipality_code','fiscal_ie_indicator','fiscal_state_registration'];
foreach(App\Models\Tenant::orderBy('id')->get() as $tenant){
 try{
  $context=clone $tenant;$context->active=true;$connection->connect($context);
  $db=Illuminate\Support\Facades\DB::connection('tenant');$schema=$db->getSchemaBuilder();
  if(!$schema->hasTable('fiscal_model_configurations')||array_diff($customerColumns,$schema->getColumnListing('customers')))throw new RuntimeException('Baseline NF-e55 ainda não foi implantado.');
  if($db->table('fiscal_outbox')->whereNull('completed_at')->exists())throw new RuntimeException('Há pendência na outbox real; revisar antes do deploy.');
  if($phase==='after'&&!isset($expected['tenants'][(string)$tenant->id]))throw new RuntimeException('Conjunto de empresas mudou durante a manutenção.');
  $result['tenants'][(string)$tenant->id]=captureDatabase($db,$expected['tenants'][(string)$tenant->id]??null,true,$newMigration,$newTables,$phase);
 }finally{$connection->disconnect();}
}
// Compare the encrypted physical XML artifacts too; never dump their content.
$fiscalRoot=config('filesystems.disks.fiscal.root');
if(!in_array($fiscalRoot,[$root.'/storage/app/fiscal-private',$root.'/storage/app/fiscal'],true)||is_link($fiscalRoot))throw new RuntimeException('Unexpected private fiscal root.');
$privateFiles=[];
if(is_dir($fiscalRoot)){
 if(realpath($fiscalRoot)!==$fiscalRoot)throw new RuntimeException('Diretório privado redirecionado por symlink.');
 $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fiscalRoot,FilesystemIterator::SKIP_DOTS));
 foreach($iterator as $file){
  if($file->isLink())throw new RuntimeException('Symlink em arquivos fiscais privados.');
  if(!$file->isFile())continue;
  $name=substr($file->getPathname(),strlen($fiscalRoot)+1);
  $digest=hash_file('sha256',$file->getPathname());if(!is_string($digest))throw new RuntimeException('Falha ao verificar arquivo fiscal privado.');
  $privateFiles[$name]=['bytes'=>$file->getSize(),'sha256'=>$digest,'mode'=>$file->getPerms()&0777,'uid'=>$file->getOwner(),'gid'=>$file->getGroup()];
 }
}
ksort($privateFiles);$result['private_fiscal_files']=['root'=>$fiscalRoot,'count'=>count($privateFiles),'files'=>$privateFiles];
echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
PHP_SNAPSHOT
chown root:vendra-web "$reader_dir/snapshot.php"
chmod 640 "$reader_dir/snapshot.php"
runuser -u vendra-web -- env VENDRA_NFSE_PREPARATION_LIVE="$live" /usr/bin/php -c "$php_cli" "$reader_dir/snapshot.php" before > "$journal/fiscal.before.json"
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
# No central/old/commercial migration: only three empty local-preparation tables.
runuser -u vendra-web -- env VENDRA_NFSE_PREPARATION_LIVE="$live" /usr/bin/php -c "$php_cli" <<'PHP'
<?php
$root=getenv('VENDRA_NFSE_PREPARATION_LIVE');chdir($root);require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$paths=['database/tenant-migrations/2026_11_12_000009_create_national_nfse.php'];
$connection=app(App\Services\TenantConnection::class);
foreach(App\Models\Tenant::orderBy('id')->get() as $tenant){
 try{$context=clone $tenant;$context->active=true;$connection->connect($context);
  foreach($paths as $path){if(Illuminate\Support\Facades\Artisan::call('migrate',['--database'=>'tenant','--path'=>$path,'--force'=>true])!==0)throw new RuntimeException('Migração incremental fiscal falhou.');}
  echo 'Estrutura NFS-e LOCAL: empresa #'.$tenant->id.PHP_EOL;
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
(cd app/Fiscal/Nfse/schemas && sha256sum --check --strict SHA256SUMS) > "$journal/schemas-after.txt"
runuser -u vendra-web -- env VENDRA_NFSE_PREPARATION_LIVE="$live" /usr/bin/php -c "$php_cli" "$reader_dir/snapshot.php" after > "$journal/fiscal.after.json"
cmp "$journal/fiscal.before.json" "$journal/fiscal.after.json"
apache2 -t -f /srv/reporvexo/ops/apache.conf
systemctl start vendra.service
wait_https || { echo 'HTTPS não ficou pronto; worker/timer não serão retomados sobre release incerta.' >&2; exit 1; }
restore_timer
if [[ $timer_was_active == 1 ]]; then systemctl is-active --quiet vendra-fiscal.timer; fi
printf '%s\n' 'complete-prior-scheduler-restored' > "$journal/status"
succeeded=1
printf 'Overlay NFS-e LOCAL aplicado; dados anteriores/55/65 e XML privados preservados; HTTPS pronto, agendamento anterior restaurado. Journal: %s\n' "$journal"
