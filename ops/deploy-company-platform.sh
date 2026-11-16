#!/bin/bash
# Phase 1 only: isolated tenants, wizard and central platform. No shared build/cutover.
set -Eeuo pipefail
umask 0077
if [[ ${1:---plan} != --apply ]]; then
 cat <<'PLAN'
Phase 1: overlay reconciled UX + company operations + central platform.
No dependency changes, seed, OS10, shared schema build/import/cutover, fiscal call.
Apply requires root, VENDRA_PLATFORM_DEPLOY_APPROVED=1, VENDRA_PLATFORM_QA_PASSED=1,
--stage /srv/vendra-platform-qa.* --manifest FILE --baseline FILE --pdv FILE
--landing FILE --manifest-sha256 SHA. All manifests reviewed/private outside Git.
Stops only Vendra/timer after natural worker drain; backup; scoped migrations;
marks existing tenant1/vexo demonstration without reseeding; compares complete
old-data projections/private fiscal files/landing before HTTP resumes; TLS then timer.
Failure restores only overlay files/caches, retains additive schema/audit/data.
Review restore compatibility in isolated QA before approving. Never restore DB automatically.
PLAN
 exit 0
fi
shift
[[ $EUID == 0 && ${VENDRA_PLATFORM_DEPLOY_APPROVED:-} == 1 && ${VENDRA_PLATFORM_QA_PASSED:-} == 1 ]] || exit 78
stage= manifest= baseline= pdv= landing= manifest_digest=
while (($#)); do
 case $1 in
 --stage) stage=${2:?};shift 2;; --manifest) manifest=${2:?};shift 2;;
 --baseline) baseline=${2:?};shift 2;; --pdv) pdv=${2:?};shift 2;;
 --landing) landing=${2:?};shift 2;; --manifest-sha256) manifest_digest=${2:?};shift 2;;
 *) echo 'Argumento desconhecido.' >&2;exit 64;; esac
done
live=/srv/reporvexo;php_cli=/opt/reporvexo-tools/php/cli.ini
[[ $manifest_digest =~ ^[a-f0-9]{64}$ && $stage == /srv/vendra-platform-qa.* && $(realpath "$live") == "$live" && $(realpath "$stage") == "$stage" ]] || exit 1
for p in "$manifest" "$baseline" "$pdv" "$landing";do [[ $p == /* && -f $p && ! -L $p ]] || exit 1;done
[[ $(sha256sum "$manifest"|cut -d ' ' -f1) == "$manifest_digest" ]] || exit 1
exec 9>/run/lock/vendra-fiscal-deploy.lock
flock -n 9 || { echo 'Outro deploy está em andamento.' >&2;exit 1; }
reader_dir=$(mktemp -d /run/vendra-platform-deploy.XXXXXX);chown root:vendra-web "$reader_dir";chmod 750 "$reader_dir"
cat > "$reader_dir/check.py" <<'PY_CHECK'
import hashlib,json,os,pathlib,re,sys
stage,live=map(pathlib.Path,sys.argv[1:3]);files=sys.argv[3:7]
def read(p):
 out={}
 for line in pathlib.Path(p).read_text().splitlines():
  m=re.fullmatch(r'([a-f0-9]{64})  ([A-Za-z0-9_./-]+)',line)
  if not m:raise SystemExit('Manifesto SHA inválido.')
  h,n=m.groups();q=pathlib.PurePosixPath(n)
  if q.is_absolute() or '..' in q.parts or n in out:raise SystemExit('Caminho inválido.')
  out[n]=h
 if not out:raise SystemExit('Manifesto vazio.')
 return out
def regular(root,n):
 p=root/n
 if not p.is_file() or p.is_symlink() or not p.resolve().is_relative_to(root.resolve()):raise SystemExit('Arquivo inválido: '+n)
 for a in p.parents:
  if a==root:break
  if a.is_symlink():raise SystemExit('Ancestral symlink.')
 return p
def digest(p):return hashlib.sha256(p.read_bytes()).hexdigest()
ov,base,pdv,landing=map(read,files)
after=os.environ.get('VENDRA_PLATFORM_CHECK_AFTER')=='1'
if len(pdv)!=101 or not set(base)<=set(ov) or set(ov)&set(landing):raise SystemExit('Escopo/protegidos divergentes.')
required={'database/migrations/2026_11_16_000001_create_platform_administration.php','database/migrations/2026_11_16_000002_create_platform_provision_requests.php','database/migrations/2026_11_16_000003_add_tenant_storage_policy.php','database/tenant-migrations/2026_11_16_000001_create_company_operations.php'}
if not required<=set(ov):raise SystemExit('Migrações exatas ausentes.')
for n,h in ov.items():
 if not n.startswith(('app/','bootstrap/','config/','routes/','resources/views/','public/','database/','docs/','ops/')) or n.startswith(('public/brand/','public/marketing','bootstrap/cache/')) or n=='resources/views/welcome.blade.php':raise SystemExit('Overlay fora do escopo ERP.')
 if n.startswith('database/') and n not in required:raise SystemExit('Migração extra recusada, inclusive OS10.')
 if digest(regular(stage,n))!=h:raise SystemExit('Stage mudou após QA: '+n)
 if after:
  if digest(regular(live,n))!=h:raise SystemExit('Overlay instalado divergiu: '+n)
 elif n in base:
  if digest(regular(live,n))!=base[n]:raise SystemExit('Baseline divergente: '+n)
 elif (live/n).exists() or (live/n).is_symlink():raise SystemExit('Caminho novo já existe: '+n)
for n,h in {**pdv,**landing}.items():
 if n not in ov and digest(regular(live,n))!=h:raise SystemExit('Protegido alterado: '+n)
 if n in pdv and n in ov and n not in base:raise SystemExit('Exceção comercial sem baseline.')
for n in ['composer.json','composer.lock','package.json','package-lock.json']:
 a=stage/n;b=live/n
 if a.exists()!=b.exists() or (a.exists() and digest(regular(stage,n))!=digest(regular(live,n))):raise SystemExit('Dependência mudou.')
for folder in ['database/migrations','database/tenant-migrations']:
 for p in (live/folder).glob('*.php'):
  n=str(p.relative_to(live))
  if not (stage/n).is_file() or digest(regular(stage,n))!=digest(regular(live,n)):raise SystemExit('Migração anterior mudou.')
# Complete file metadata outside overlay, including all landing files; no secrets printed.
def scan():
 out={}
 for folder in ['app','bootstrap','config','routes','resources','public','ops','database']:
  for p in (live/folder).rglob('*'):
   n=str(p.relative_to(live))
   if n.startswith('bootstrap/cache/'):continue
   if p.is_symlink():raise SystemExit('Symlink em código.')
   if p.is_file() and n not in ov:
    st=p.stat();out[n]=[digest(p),st.st_uid,st.st_gid,st.st_mode&0o777]
 return out
if len(sys.argv)==8:
 path=pathlib.Path(sys.argv[7]);current=scan()
 if path.exists():
  if json.loads(path.read_text())!=current:raise SystemExit('Código fora do overlay mudou.')
 else:path.write_text(json.dumps(current,sort_keys=True))
PY_CHECK
check() {
 if ! python3 "$reader_dir/check.py" "$stage" "$live" "$manifest" "$baseline" "$pdv" "$landing" "$journal/code-before.json";then
  [[ ${timer_paused:-0} == 0 ]] || baseline_diverged=1
  return 1
 fi
}
# First check before touching any service (code snapshot path is added after journal creation).
python3 "$reader_dir/check.py" "$stage" "$live" "$manifest" "$baseline" "$pdv" "$landing"
while read -r sha path;do if [[ $path == *.php && $path != *.blade.php ]];then /usr/bin/php -c "$php_cli" -l "$stage/$path" >/dev/null;fi;done < "$manifest"
wait_https() {
 local n;for ((n=0;n<10;n++));do
  if curl --resolve 2.25.146.67:8445:127.0.0.1 --connect-timeout 2 --max-time 4 --fail --silent --show-error -o /dev/null https://2.25.146.67:8445/app/vexo/login;then return 0;fi;sleep 1
 done;return 1
}
systemctl is-active --quiet vendra.service || { echo 'Vendra precisa estar ativo no início.' >&2;exit 1; }
timer_was_active=0;systemctl is-active --quiet vendra-fiscal.timer && timer_was_active=1
worker_state=$(systemctl show -p ActiveState --value vendra-fiscal.service)
case $worker_state in inactive|active|activating) ;; *) exit 1;; esac
install -d -m700 /var/lib/vendra/platform-deploy
journal=$(mktemp -d /var/lib/vendra/platform-deploy/release.XXXXXX)
cp "$manifest" "$journal/overlay.sha256";cp "$baseline" "$journal/baseline.sha256";cp "$pdv" "$journal/protected.sha256";cp "$landing" "$journal/landing.sha256"
printf 'timer_was_active=%s\nworker_before=%s\nmanifest=%s\n' "$timer_was_active" "$worker_state" "$manifest_digest" > "$journal/state.txt"
check
paused=0;overlay_started=0;success=0;timer_paused=0;baseline_diverged=0
restore_files() {
 python3 - "$live" "$journal" <<'PY_RESTORE'
import hashlib,json,os,pathlib,shutil,sys
live,journal=map(pathlib.Path,sys.argv[1:3]);meta=json.loads((journal/'originals.json').read_text())
for n,m in meta.items():
 p=live/n
 if p.is_symlink() or any(a.is_symlink() for a in p.parents if a!=live):raise SystemExit('Symlink durante rollback.')
 if m is None:
  if p.exists():p.unlink()
 else:
  shutil.copyfile(journal/'originals'/n,p);os.chown(p,m['uid'],m['gid']);os.chmod(p,m['mode'])
PY_RESTORE
}
caches() {
 (cd "$live" && runuser -u vendra-web -- /usr/bin/php -c "$php_cli" artisan config:clear && runuser -u vendra-web -- /usr/bin/php -c "$php_cli" artisan route:clear && runuser -u vendra-web -- /usr/bin/php -c "$php_cli" artisan view:clear && runuser -u vendra-web -- /usr/bin/php -c "$php_cli" artisan view:cache) > "$journal/cache.log" 2>&1
 while IFS= read -r -d '' p;do /usr/bin/php -c "$php_cli" -l "$p" >/dev/null;done < <(find "$live/storage/framework/views" -type f -name '*.php' -print0)
 for p in "$live/storage/framework/views" "$live/bootstrap/cache";do chown -R vendra-web:vendra-web "$p";find "$p" -type d -exec chmod 750 {} +;find "$p" -type f -exec chmod 640 {} +;done
}
finish() {
 local code=$?;trap - EXIT
 if [[ $success != 1 && $timer_paused == 1 ]];then
  if [[ $baseline_diverged == 1 ]];then
   systemctl stop vendra.service || true
   echo "Divergência externa: Vendra/timer ficam pausados para revisão; nenhum rollback cego. Journal: $journal" >&2
   exit 1
  fi
  if [[ $overlay_started == 1 ]];then
   systemctl stop vendra.service || true
   if ! restore_files || ! caches;then echo "Rollback de arquivos exige revisão; somente Vendra/timer pausados. Journal: $journal" >&2;exit 1;fi
   echo 'Arquivos restaurados; schema aditivo/audit/dados retidos. Sem restauração de banco.' >&2
  fi
  if [[ $paused == 1 ]];then
   if ! systemctl start vendra.service || ! wait_https;then echo "HTTPS da versão anterior exige revisão. Journal: $journal" >&2;exit 1;fi
  fi
  [[ $timer_was_active == 0 ]] || systemctl start vendra-fiscal.timer
 fi
 if [[ -d $reader_dir ]];then rm -f "$reader_dir/check.py" "$reader_dir/snapshot.php" "$reader_dir/projection.json" "$reader_dir/migrate.php";rmdir "$reader_dir" || true;fi
 exit "$code"
}
trap finish EXIT
systemctl stop vendra-fiscal.timer;timer_paused=1
for ((n=0;n<60;n++));do
 state=$(systemctl show -p ActiveState --value vendra-fiscal.service)
 [[ $state != failed ]] || exit 1
 [[ $state != inactive ]] || break
 sleep 1
done
[[ $(systemctl show -p ActiveState --value vendra-fiscal.service) == inactive ]] || exit 1
paused=1;systemctl stop vendra.service
cat > "$reader_dir/snapshot.php" <<'PHP_SNAPSHOT'
<?php
$root=getenv('VENDRA_EXPERIENCE_LIVE');chdir($root);require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$phase=$argv[1]??'';
if(!in_array($phase,['before','after'],true))throw new RuntimeException('Invalid snapshot phase.');
$expected=$phase==='after'?json_decode(file_get_contents(__DIR__.'/projection.json'),true,512,JSON_THROW_ON_ERROR):null;
function allTables($db):array{
 $tables=$db->table('information_schema.tables')->where('table_schema',$db->getDatabaseName())->where('table_type','BASE TABLE')->orderBy('TABLE_NAME')->pluck('TABLE_NAME')->all();
 foreach($tables as $table){if(!preg_match('/^[a-z0-9_]+$/D',$table))throw new RuntimeException('Unexpected table identifier.');}
 return $tables;
}
$centralMigrations=['2026_11_16_000001_create_platform_administration','2026_11_16_000002_create_platform_provision_requests','2026_11_16_000003_add_tenant_storage_policy'];
$tenantMigrations=['2026_11_16_000001_create_company_operations'];
function captureTable($db,string $name,?array $expected,string $kind):array{
 global $phase,$centralMigrations,$tenantMigrations;
 $actual=$db->getSchemaBuilder()->getColumnListing($name);$columns=$expected['columns']??$actual;
 $added=($kind==='central'&&$name==='tenants')?['storage_mode','is_demonstration','platform_version','provisioned_at']:(($kind==='tenant'&&$name==='sales')?['commission_basis']:[]);
 if($expected&&(array_values(array_diff($actual,$columns))!==$added||array_diff($columns,$actual)))throw new RuntimeException('Schema fora da projeção aprovada.');
 $primary=[];foreach($db->getSchemaBuilder()->getIndexes($name) as $index){if($index['primary']??false){$primary=$index['columns'];break;}}
 if(!$primary||($expected&&$primary!==$expected['primary']))throw new RuntimeException('PK mudou ou ausente.');
 $query=$db->table($name)->select($columns);foreach($primary as $column)$query->orderBy($column);
 if($phase==='after'&&$name==='migrations')$query->whereNotIn('migration',$kind==='central'?$centralMigrations:$tenantMigrations);
 $hash=hash_init('sha256');$rows=0;foreach($query->cursor() as $row){hash_update($hash,serialize((array)$row));$rows++;}
 $definition=array_values(array_filter($db->getSchemaBuilder()->getColumns($name),fn($c)=>in_array($c['name'],$columns,true)));
 $indexes=$db->getSchemaBuilder()->getIndexes($name);
 if($kind==='central'&&$name==='tenants'){
  $names=array_column($indexes,'name');
  if($phase==='before'&&!in_array('tenants_db_name_unique',$names,true))throw new RuntimeException('Índice único anterior não confirmado.');
  if($phase==='after'&&(in_array('tenants_db_name_unique',$names,true)||!in_array('tenants_storage_mode_db_name_index',$names,true)))throw new RuntimeException('Índice central novo divergente.');
  $indexes=array_values(array_filter($indexes,fn($i)=>!in_array($i['name'],['tenants_db_name_unique','tenants_storage_mode_db_name_index'],true)));
 }
 usort($indexes,fn($a,$b)=>strcmp($a['name'],$b['name']));
 return ['columns'=>$columns,'primary'=>$primary,'definition'=>$definition,'indexes'=>$indexes,'rows'=>$rows,'sha256'=>hash_final($hash)];
}
function captureDatabase($db,?array $expected,string $kind):array{
 global $phase;
 $tables=allTables($db);$new=$kind==='central'?['platform_admins','platform_audits','platform_provision_requests']:['company_operations','company_operation_audits'];sort($new);
 if($expected){$extra=array_values(array_diff($tables,array_keys($expected)));sort($extra);if($extra!==$new||array_diff(array_keys($expected),$tables))throw new RuntimeException('Tabelas fora do schema aprovado.');}
 $out=[];foreach($expected?array_keys($expected):$tables as $table)$out[$table]=captureTable($db,$table,$expected[$table]??null,$kind);
 if($phase==='after'){
  if($kind==='tenant'){
   foreach($new as $table)if($db->table($table)->count()!==0)throw new RuntimeException('Configuração tenant foi preenchida indevidamente.');
   if($db->table('sales')->whereNull('commission_basis')->orWhere('commission_basis','<>','cash')->exists())throw new RuntimeException('Vendas anteriores não mantiveram regime cash.');
  }else{
   foreach(['platform_admins','platform_provision_requests'] as $table)if($db->table($table)->count()!==0)throw new RuntimeException('Seed central indevido.');
   if($db->table('platform_audits')->count()!==1||!$db->table('platform_audits')->where('tenant_id',1)->whereNull('platform_admin_id')->where('action','tenant.demonstration_tagged')->exists())throw new RuntimeException('Auditoria de demonstração divergente.');
   foreach($db->table('tenants')->get() as $t){if($t->storage_mode!=='isolated'||$t->provisioned_at!==null||(bool)$t->is_demonstration!==((int)$t->id===1)||(int)$t->platform_version!==((int)$t->id===1?1:0))throw new RuntimeException('Política central divergente.');}
  }
 }
 return $out;
}
$central=Illuminate\Support\Facades\DB::connection('central');
if($phase==='after'&&config('tenancy.shared.enabled'))throw new RuntimeException('Fase1 deve manter shared desativado.');
$result=['central'=>captureDatabase($central,$expected['central']??null,'central'),'tenants'=>[]];
// Read original singleton credentials through the application, never print them.
$connection=app(App\Services\TenantConnection::class);
foreach(App\Models\Tenant::orderBy('id')->get() as $tenant){
 try{
  $context=clone $tenant;$context->active=true;$connection->connect($context);$db=Illuminate\Support\Facades\DB::connection('tenant');
  if($db->table('fiscal_outbox')->whereNull('completed_at')->exists())throw new RuntimeException('Outbox pendente.');
  if((int)$tenant->id===1&&($tenant->slug!=='vexo'||!$db->table('demo_runs')->where('code','computer-store-v1')->whereNotNull('completed_at')->exists()))throw new RuntimeException('Demonstração original não confirmada.');
  if($phase==='after'&&!isset($expected['tenants'][(string)$tenant->id]))throw new RuntimeException('Conjunto de tenants mudou.');
  $result['tenants'][(string)$tenant->id]=captureDatabase($db,$expected['tenants'][(string)$tenant->id]??null,'tenant');
 }finally{$connection->disconnect();}
}
if($phase==='after'&&array_keys($result['tenants'])!==array_keys($expected['tenants']))throw new RuntimeException('Conjunto de tenants divergente.');
// Hash encrypted physical XML artifacts and their access metadata, never content.
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
// Runtime UI overlay never contains landing/marketing/brand files. Their actual
// production bytes are compared too, without replacing newer marketing work.
$landingPaths=['resources/views/welcome.blade.php','public/favicon.ico','public/robots.txt'];
foreach(glob($root.'/public/marketing*')?:[] as $path){if(is_file($path))$landingPaths[]=substr($path,strlen($root)+1);}
if(is_dir($root.'/public/brand')){
 $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/public/brand',FilesystemIterator::SKIP_DOTS));
 foreach($iterator as $file){if($file->isLink())throw new RuntimeException('Symlink na identidade visual.');if($file->isFile())$landingPaths[]=substr($file->getPathname(),strlen($root)+1);}
}
$landing=[];
foreach(array_unique($landingPaths) as $name){
 $path=$root.'/'.$name;if(!file_exists($path))continue;
 if(is_link($path)||!is_file($path))throw new RuntimeException('Arquivo de landing inesperado.');
 $digest=hash_file('sha256',$path);if(!is_string($digest))throw new RuntimeException('Falha ao verificar a landing.');
 $landing[$name]=['bytes'=>filesize($path),'sha256'=>$digest];
}
ksort($landing);$result['landing_files']=['count'=>count($landing),'files'=>$landing];
echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
PHP_SNAPSHOT
chown root:vendra-web "$reader_dir/snapshot.php";chmod 640 "$reader_dir/snapshot.php"
/usr/bin/php -c "$php_cli" -l "$reader_dir/snapshot.php" >/dev/null
runuser -u vendra-web -- env VENDRA_EXPERIENCE_LIVE="$live" /usr/bin/php -c "$php_cli" "$reader_dir/snapshot.php" before > "$journal/data-before.json"
install -m640 -o root -g vendra-web "$journal/data-before.json" "$reader_dir/projection.json"
VENDRA_BACKUP_ACK_QUIESCED=1 sh "$live/ops/backup.sh" > "$journal/backup-result.txt"
backup_path=$(python3 - "$journal/backup-result.txt" <<'PY_BACKUP'
import hashlib,pathlib,re,subprocess,sys
m=re.search(r'^Backup local criado em (/var/backups/vendra/[0-9TZ]+-[A-Za-z0-9]+)\. Ainda requer',pathlib.Path(sys.argv[1]).read_text(),re.M)
if not m:raise SystemExit('Backup não identificado.')
p=pathlib.Path(m[1])
if p.is_symlink() or p.resolve()!=p or p.parent!=pathlib.Path('/var/backups/vendra'):raise SystemExit('Caminho backup inválido.')
required={'databases.txt','databases.sql','application.tar.gz'};allowed=required|{'controlplane.tar.gz'};seen=set()
for line in (p/'SHA256SUMS').read_text().splitlines():
 m=re.fullmatch(r'([a-f0-9]{64})  ([A-Za-z0-9_.-]+)',line)
 if not m or m[2] not in allowed or m[2] in seen:raise SystemExit('Manifesto backup inválido.')
 h,n=m.groups();f=p/n
 if f.is_symlink() or not f.is_file() or f.stat().st_size==0:raise SystemExit('Backup incompleto.')
 digest=hashlib.sha256()
 with f.open('rb') as stream:
  for block in iter(lambda:stream.read(1024*1024),b''):digest.update(block)
 if digest.hexdigest()!=h:raise SystemExit('SHA backup inválido.')
 if n.endswith('.tar.gz'):subprocess.run(['tar','-tzf',str(f)],stdout=subprocess.DEVNULL,check=True)
 seen.add(n)
if not required<=seen:raise SystemExit('Backup incompleto.')
print(p)
PY_BACKUP
)
printf '%s\n' "$backup_path" > "$journal/backup-path.txt"
# Recheck immediately after backup, before any write to application files.
check
python3 - "$live" "$journal" "$manifest" <<'PY_ORIGINALS'
import json,os,pathlib,shutil,sys
live,journal=map(pathlib.Path,sys.argv[1:3]);out={}
for line in pathlib.Path(sys.argv[3]).read_text().splitlines():
 n=line.split('  ',1)[1];p=live/n
 if p.exists():
  st=p.stat();out[n]={'uid':st.st_uid,'gid':st.st_gid,'mode':st.st_mode&0o777};dest=journal/'originals'/n;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(p,dest)
 else:out[n]=None
(journal/'originals.json').write_text(json.dumps(out))
PY_ORIGINALS
# Copying originals may take time; protect against uncooperative deployers again.
check
overlay_started=1
while read -r sha path;do
 install -D -m640 -o root -g vendra-web "$stage/$path" "$live/$path"
 parent=$(dirname "$live/$path")
 while [[ $parent != "$live" ]];do chown root:vendra-web "$parent";chmod 750 "$parent";parent=$(dirname "$parent");done
done < "$manifest"
(cd "$live" && runuser -u vendra-web -- /usr/bin/php -c "$php_cli" artisan config:clear) > "$journal/config-clear.log" 2>&1
cat > "$reader_dir/migrate.php" <<'PHP_MIGRATE'
<?php
$root='/srv/reporvexo';chdir($root);require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('tenancy.shared.enabled'))throw new RuntimeException('Shared precisa permanecer desligado na fase1.');
$central=['2026_11_16_000001_create_platform_administration','2026_11_16_000002_create_platform_provision_requests','2026_11_16_000003_add_tenant_storage_policy'];
foreach($central as $migration){if(Illuminate\Support\Facades\Artisan::call('migrate',['--database'=>'central','--path'=>'database/migrations/'.$migration.'.php','--force'=>true])!==0)throw new RuntimeException('Migração central falhou.');}
$connection=app(App\Services\TenantConnection::class);
foreach(App\Models\Tenant::orderBy('id')->get() as $tenant){
 try{
  if($tenant->storage_mode!=='isolated')throw new RuntimeException('Fase1 não aceita cliente compartilhado.');
  $context=clone $tenant;$context->active=true;$connection->connect($context);
  if(Illuminate\Support\Facades\Artisan::call('migrate',['--database'=>'tenant','--path'=>'database/tenant-migrations/2026_11_16_000001_create_company_operations.php','--force'=>true])!==0)throw new RuntimeException('Migração tenant falhou.');
  if((int)$tenant->id===1){
   if($tenant->slug!=='vexo'||!Illuminate\Support\Facades\DB::connection('tenant')->table('demo_runs')->where('code','computer-store-v1')->whereNotNull('completed_at')->exists())throw new RuntimeException('Demonstração não confirmada.');
  }
 }finally{$connection->disconnect();}
}
Illuminate\Support\Facades\DB::connection('central')->transaction(function(){
 $db=Illuminate\Support\Facades\DB::connection('central');$t=$db->table('tenants')->where('id',1)->lockForUpdate()->first();
 if(!$t||$t->slug!=='vexo'||$t->storage_mode!=='isolated'||(bool)$t->is_demonstration||(int)$t->platform_version!==0)throw new RuntimeException('Tenant original/versão divergente; sem auto-reseed ou reexecução.');
 // Query builder deliberately preserves updated_at and every preexisting value.
 $db->table('tenants')->where('id',1)->update(['is_demonstration'=>true,'platform_version'=>1]);
 App\Platform\PlatformAudit::create(['tenant_id'=>1,'platform_admin_id'=>null,'action'=>'tenant.demonstration_tagged','actor_name'=>'Vexo/implantação','changes'=>['before_is_demonstration'=>false,'after_is_demonstration'=>true,'before_version'=>0,'after_version'=>1]]);
});
echo "Migrações exatas e marcação da demonstração concluídas; sem seed.\n";
PHP_MIGRATE
chown root:vendra-web "$reader_dir/migrate.php";chmod 640 "$reader_dir/migrate.php"
/usr/bin/php -c "$php_cli" -l "$reader_dir/migrate.php" >/dev/null
runuser -u vendra-web -- /usr/bin/php -c "$php_cli" "$reader_dir/migrate.php" > "$journal/migration.log" 2>&1
caches
(cd "$live" && sha256sum --check --strict "$manifest") > "$journal/overlay-verified.txt"
VENDRA_PLATFORM_CHECK_AFTER=1 check
runuser -u vendra-web -- env VENDRA_EXPERIENCE_LIVE="$live" /usr/bin/php -c "$php_cli" "$reader_dir/snapshot.php" after > "$journal/data-after.json"
cmp "$journal/data-before.json" "$journal/data-after.json"
apache2 -t -f "$live/ops/apache.conf"
systemctl start vendra.service
wait_https || exit 1
for path in / /vexo-admin/login;do
 curl --resolve 2.25.146.67:8445:127.0.0.1 --connect-timeout 2 --max-time 5 --fail --silent --show-error -o /dev/null "https://2.25.146.67:8445$path"
done
[[ $timer_was_active == 0 ]] || systemctl start vendra-fiscal.timer
printf '%s\n' 'complete-additive-schema-demo-preserved-shared-disabled' > "$journal/status"
success=1
printf 'Fase1 concluída; schema aditivo e demonstração preservados, shared desligado. Journal privado: %s\n' "$journal"
