#!/bin/bash
# PLAN by default. Reviewed source overlay only; no fiscal transmission or PFX import.
set -Eeuo pipefail
umask 0077
if [[ ${1:-} != --apply ]]; then
 cat <<'PLAN'
PLAN ONLY — fiscal overlay based on main 81232f2.
Required inputs (absolute paths):
  --stage <reviewed QA source> --manifest <SHA256SUMS overlay>
  --baseline <SHA256SUMS original files touched by overlay>
  --pdv <SHA256SUMS protected PDV/seller/cash files>
  --manifest-sha256 <independently reviewed manifest digest>
Apply requires VENDRA_FISCAL_DEPLOY_APPROVED=1 and VENDRA_FISCAL_QA_PASSED=1.
Checks hashes, base/PDV and platform; pauses only Vendra; takes local recovery
backup; overlays only listed fiscal files; uses private PHP SOAP configuration;
applies only the five fiscal migration files; keeps private fiscal files private;
starts only Vendra. Fiscal timer is installed but disabled. No seed, PFX,
credentials, numbering, official-network test, emission, worker invocation or
commercial/PDV migrations. See docs/DEPLOY_FISCAL.md before any apply.
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
  *) printf 'Argumento não reconhecido.\n' >&2; exit 1;;
 esac
done
[[ $EUID == 0 && ${VENDRA_FISCAL_DEPLOY_APPROVED:-} == 1 && ${VENDRA_FISCAL_QA_PASSED:-} == 1 ]] || { echo 'Faltam root, revisão explícita e QA aprovado.' >&2; exit 1; }
[[ $manifest_digest =~ ^[a-f0-9]{64}$ ]] || exit 1
for input in "$stage" "$manifest" "$baseline" "$pdv"; do
 [[ $input == /* && -e $input && ! -L $input ]] || { echo 'Caminho inválido ou symlink.' >&2; exit 1; }
done
live=/srv/reporvexo
php_cli=/opt/reporvexo-tools/php/cli.ini
soap=/opt/reporvexo-tools/php/extracted/usr/lib/php/20230831/soap.so
[[ $(realpath "$stage") == /srv/vendra-fiscal-qa.* && $(realpath "$live") == "$live" ]] || exit 1
[[ -f $soap && -f $php_cli && -f /etc/php/8.3/apache2/php.ini ]] || exit 1
[[ $(sha256sum "$manifest" | cut -d ' ' -f1) == "$manifest_digest" ]] || { echo 'Manifesto não corresponde ao revisado.' >&2; exit 1; }
exec 9>/run/lock/vendra-fiscal-deploy.lock
flock -n 9 || { echo 'Outra implantação fiscal está em execução.' >&2; exit 1; }
# Manifest and base must be cross-checked together. Reject any unrelated file,
# secret, writable data or deleted/renamed path; never copy the whole QA tree.
python3 - "$stage" "$live" "$manifest" "$baseline" "$pdv" <<'PY'
import hashlib,pathlib,re,sys
stage,live=map(pathlib.Path,sys.argv[1:3])
fixed={
'app/Http/Controllers/BusinessController.php','app/Http/Controllers/CommerceController.php',
'app/Models/Tenant.php','app/Services/Checkout.php','bootstrap/app.php','composer.json','composer.lock',
'config/filesystems.php','resources/views/business.blade.php','resources/views/layout.blade.php',
'resources/views/sales/show.blade.php','routes/web.php','ops/apache.conf','ops/vendra-fiscal.service',
'ops/vendra-fiscal.timer','ops/deploy-fiscal.sh','docs/FISCAL.md','docs/REVISAO_FISCAL_DIRETA.md',
'docs/VALIDACAO_FISCAL.md','docs/DEPLOY_FISCAL.md','app/Console/Commands/ProcessFiscal.php',
'app/Http/Controllers/FiscalController.php','app/Http/Controllers/FiscalEmissionController.php',
'database/migrations/2026_11_12_000001_add_fiscal_demonstration_guard.php',
'database/tenant-migrations/2026_11_12_000001_create_fiscal_foundation.php',
'database/tenant-migrations/2026_11_12_000002_create_fiscal_preparations.php',
'database/tenant-migrations/2026_11_12_000003_enable_fiscal_transport.php',
'database/tenant-migrations/2026_11_12_000004_freeze_direct_sefaz_xml.php',
'app/Models/FiscalCredential.php','app/Models/FiscalDocument.php','app/Models/FiscalIssuer.php',
'app/Models/FiscalItemRule.php','app/Models/FiscalPreparation.php','app/Models/FiscalSetting.php',
}
def read(path):
 data={}
 for line in pathlib.Path(path).read_text().splitlines():
  m=re.fullmatch(r'([a-f0-9]{64})  ([A-Za-z0-9_./-]+)',line)
  if not m:raise SystemExit('Formato de manifesto inválido.')
  digest,name=m.groups(); p=pathlib.PurePosixPath(name)
  if p.is_absolute() or '..' in p.parts or name in data:raise SystemExit('Caminho de manifesto inválido.')
  data[name]=digest
 if not data:raise SystemExit('Manifesto vazio.')
 return data
overlay,base,pdv=map(read,sys.argv[3:6])
for name,digest in overlay.items():
 allowed=name in fixed or (name.startswith('app/Fiscal/') and name.endswith('.php')) or (name.startswith('resources/views/fiscal/') and name.endswith('.blade.php'))
 if not allowed:raise SystemExit('Arquivo fora do escopo fiscal: '+name)
 p=stage/name
 if p.is_symlink() or not p.is_file() or not p.resolve().is_relative_to(stage.resolve()):raise SystemExit('Origem inválida: '+name)
 if hashlib.sha256(p.read_bytes()).hexdigest()!=digest:raise SystemExit('Arquivo alterado após QA: '+name)
 if (live/name).exists() and name not in base:raise SystemExit('Arquivo novo já existe na VPS: '+name)
for name,digest in {**base,**pdv}.items():
 if name in pdv and name in overlay:raise SystemExit('PDV protegido não pode estar no overlay: '+name)
 p=live/name
 if p.is_symlink() or not p.is_file() or hashlib.sha256(p.read_bytes()).hexdigest()!=digest:raise SystemExit('Base/PDV divergiu na VPS: '+name)
 if name in pdv:
  q=stage/name
  if not q.is_file() or q.is_symlink() or hashlib.sha256(q.read_bytes()).hexdigest()!=digest:raise SystemExit('PDV no stage divergiu da base: '+name)
if set(base)-set(overlay):raise SystemExit('Base inclui arquivo sem overlay.')
for p in live.rglob('*'):
 if p.is_symlink():raise SystemExit('Symlink no destino: revisar antes do deploy.')
print('Manifesto fiscal, base main e PDV conferidos.')
PY
systemctl is-active --quiet vendra.service || { echo 'Vendra já está parado; revisar estado antes do deploy.' >&2; exit 1; }
for unit in vendra-fiscal.service vendra-fiscal.timer; do
 if systemctl is-active --quiet "$unit"; then
  echo 'Processamento fiscal já está ativo: drenar/verificar incertezas antes desta implantação.' >&2
  exit 1
 fi
done
/usr/bin/php -c "$php_cli" -r 'foreach (["soap","curl","openssl","dom","bcmath","pdo_mysql"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR,"Extensão necessária ausente.\n"); exit(1); } }'
while read -r digest relative; do
 if [[ $relative == *.php && $relative != *.blade.php ]]; then
  /usr/bin/php -c "$php_cli" -l "$stage/$relative" >/dev/null
 fi
done < "$manifest"
(cd "$stage" && COMPOSER_ALLOW_SUPERUSER=1 /usr/bin/php -c "$php_cli" /opt/reporvexo-tools/composer.phar check-platform-reqs --no-dev)
# This is the first application-state mutation. Failed deployment stays stopped;
# never attempt destructive schema rollback, reseed or automatic renumbering.
paused=0
on_error() {
 code=$?
 if [[ $paused == 1 ]]; then
  systemctl stop vendra.service || true
  printf 'Deploy interrompido; Vendra permanece em manutenção. Não reexecutar automaticamente. Backup/journal: %s\n' "${journal:-não concluído}" >&2
 fi
 exit "$code"
}
trap on_error EXIT
systemctl stop vendra.service
paused=1
install -d -m700 /var/lib/vendra/fiscal-deploy
journal=$(mktemp -d /var/lib/vendra/fiscal-deploy/review.XXXXXX)
printf '%s\n' "$manifest_digest" > "$journal/reviewed-manifest.sha256"
cp "$manifest" "$journal/overlay.sha256"
cp "$baseline" "$journal/baseline.sha256"
cp "$pdv" "$journal/pdv.sha256"
VENDRA_BACKUP_ACK_QUIESCED=1 sh "$live/ops/backup.sh" > "$journal/backup-result.txt"
# Preserve runtime configuration outside the application archive too.
cp -a "$live/ops/apache.conf" "$journal/apache.before.conf"
cp -a /etc/systemd/system/vendra.service "$journal/vendra.before.service"
if [[ -f /opt/reporvexo-tools/php/apache2/php.ini ]]; then cp -a /opt/reporvexo-tools/php/apache2/php.ini "$journal/php-apache.before.ini"; fi
# Scoped PHP paths only; never install/enable an extension globally.
for dir in /opt/reporvexo-tools /opt/reporvexo-tools/php /opt/reporvexo-tools/php/extracted /opt/reporvexo-tools/php/extracted/usr /opt/reporvexo-tools/php/extracted/usr/lib /opt/reporvexo-tools/php/extracted/usr/lib/php /opt/reporvexo-tools/php/extracted/usr/lib/php/20230831; do
 [[ -d $dir && ! -L $dir ]] || exit 1
 chown root:vendra-web "$dir"
 chmod 750 "$dir"
done
chown root:vendra-web "$soap" "$php_cli"
chmod 640 "$soap" "$php_cli"
install -d -m750 -o root -g vendra-web /opt/reporvexo-tools/php/apache2
cp /etc/php/8.3/apache2/php.ini /opt/reporvexo-tools/php/apache2/php.ini
printf '\nextension=%s\n' "$soap" >> /opt/reporvexo-tools/php/apache2/php.ini
chown root:vendra-web /opt/reporvexo-tools/php/apache2/php.ini
chmod 640 /opt/reporvexo-tools/php/apache2/php.ini
# Check CLI platform as the actual worker identity; a root-only check is insufficient.
runuser -u vendra-web -- /usr/bin/php -c "$php_cli" -r 'exit(class_exists("SoapClient") ? 0 : 1);'
while read -r digest relative; do
 install -D -m640 -o root -g vendra-web "$stage/$relative" "$live/$relative"
done < "$manifest"
cd "$live"
COMPOSER_ALLOW_SUPERUSER=1 /usr/bin/php -c "$php_cli" /opt/reporvexo-tools/composer.phar install --no-dev --prefer-dist --no-interaction --optimize-autoloader
/usr/bin/php -c "$php_cli" /opt/reporvexo-tools/composer.phar check-platform-reqs --no-dev
/usr/bin/php -c "$php_cli" artisan config:clear
/usr/bin/php -c "$php_cli" artisan route:clear
/usr/bin/php -c "$php_cli" artisan view:clear
/usr/bin/php -c "$php_cli" artisan migrate --database=central --path=database/migrations/2026_11_12_000001_add_fiscal_demonstration_guard.php --force
# Root-only maintenance helper. No worker, credential configuration or external I/O.
VENDRA_FISCAL_DEPLOY_ROOT="$live" /usr/bin/php -c "$php_cli" <<'PHP'
<?php
$root=getenv('VENDRA_FISCAL_DEPLOY_ROOT');chdir($root);require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$paths=[
'database/tenant-migrations/2026_11_12_000001_create_fiscal_foundation.php',
'database/tenant-migrations/2026_11_12_000002_create_fiscal_preparations.php',
'database/tenant-migrations/2026_11_12_000003_enable_fiscal_transport.php',
'database/tenant-migrations/2026_11_12_000004_freeze_direct_sefaz_xml.php',
];
$connection=app(App\Services\TenantConnection::class);
foreach(App\Models\Tenant::orderBy('id')->get() as $tenant){
 try {
  // Include inactive retained databases without enabling their HTTP access.
  $maintenanceTenant=clone $tenant;$maintenanceTenant->active=true;
  $connection->connect($maintenanceTenant);
  foreach($paths as $path){
   if(Illuminate\Support\Facades\Artisan::call('migrate',['--database'=>'tenant','--path'=>$path,'--force'=>true])!==0)throw new RuntimeException('Fiscal migration failed.');
  }
  echo 'Migrações fiscais concluídas: empresa #'.$tenant->id.PHP_EOL;
 }finally{$connection->disconnect();}
}
PHP
/usr/bin/php -c "$php_cli" artisan view:cache
# Blade compilation can succeed while generated PHP is syntactically invalid.
while IFS= read -r -d '' compiled; do
 /usr/bin/php -c "$php_cli" -l "$compiled" >/dev/null
done < <(find storage/framework/views -type f -name '*.php' -print0)
sh ops/permissions.sh
fiscal_dir=$(/usr/bin/php -c "$php_cli" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config("filesystems.disks.fiscal.root");')
case "$fiscal_dir" in "$live/storage/app/fiscal-private"|"$live/storage/app/fiscal") ;; *) echo 'Disco fiscal privado inesperado; revisar configuração.' >&2; exit 1;; esac
# Restore tighter permissions after the generic permission normalization.
install -d -m700 -o vendra-web -g vendra-web "$fiscal_dir"
find "$fiscal_dir" -type d -exec chmod 700 {} +
find "$fiscal_dir" -type f -exec chmod 600 {} +
install -m644 ops/vendra-fiscal.service ops/vendra-fiscal.timer /etc/systemd/system/
systemctl daemon-reload
systemctl disable vendra-fiscal.timer
apache2 -t -f /srv/reporvexo/ops/apache.conf
(cd "$live" && sha256sum --check "$pdv")
systemctl start vendra.service
curl --resolve 2.25.146.67:8445:127.0.0.1 --fail --silent --show-error --output /dev/null --write-out 'Login HTTPS %{http_code}\n' https://2.25.146.67:8445/app/vexo/login
printf '%s\n' 'complete-worker-disabled' > "$journal/status"
paused=0
printf 'Overlay fiscal aplicado. Timer fiscal continua desabilitado. Journal privado: %s\n' "$journal"
