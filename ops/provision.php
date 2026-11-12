<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantConnection;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

// Run once, from CLI as the VPS administrator. No credentials are printed.
if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    exit("Execute como administrador no terminal.\n");
}
$base = dirname(__DIR__);
if (file_exists($base.'/storage/app/provisioned')) {
    exit("Instância já provisionada.\n");
}
require $base.'/vendor/autoload.php';
$pdo = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$passwords = [];
foreach (['vendra_central' => 'vendra_central', 'vendra_t_vexo' => 'vendra_vexo', 'vendra_test_central' => 'vendra_test', 'vendra_t_test_a' => 'vendra_test', 'vendra_t_test_b' => 'vendra_test'] as $db => $user) {
    $passwords[$user] ??= bin2hex(random_bytes(24));
    // Never adopt existing databases/accounts: fail closed instead of changing their passwords.
    if ($pdo->query('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='.$pdo->quote($db))->fetchColumn()) {
        exit("Banco reservado já existe: $db. Inspecione antes de continuar.\n");
    }
}
foreach ($passwords as $user => $password) {
    $exists = $pdo->query('SELECT COUNT(*) FROM mysql.user WHERE User='.$pdo->quote($user)." AND Host='127.0.0.1'")->fetchColumn();
    if ($exists) {
        exit("Usuário reservado já existe: $user.\n");
    }
}
foreach ($passwords as $user => $password) {
    $pdo->exec('CREATE USER '.$pdo->quote($user)."@'127.0.0.1' IDENTIFIED BY ".$pdo->quote($password));
}
foreach (['vendra_central' => 'vendra_central', 'vendra_t_vexo' => 'vendra_vexo', 'vendra_test_central' => 'vendra_test', 'vendra_t_test_a' => 'vendra_test', 'vendra_t_test_b' => 'vendra_test'] as $db => $user) {
    $pdo->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("GRANT ALL PRIVILEGES ON `$db`.* TO ".$pdo->quote($user)."@'127.0.0.1'");
}
$env = file_get_contents($base.'/.env.example');
$settings = ['APP_NAME' => 'Vendra', 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_URL' => 'https://2.25.146.67:8445', 'APP_LOCALE' => 'pt_BR', 'DB_CONNECTION' => 'central', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'vendra_central', 'DB_USERNAME' => 'vendra_central', 'DB_PASSWORD' => $passwords['vendra_central'], 'SESSION_DRIVER' => 'file', 'SESSION_SECURE_COOKIE' => 'true', 'SESSION_COOKIE' => 'vendra_session', 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync', 'LOG_LEVEL' => 'warning'];
foreach ($settings as $key => $value) {
    $env = preg_replace('/^#?\s*'.preg_quote($key, '/').'=.*/m', '', $env);
    $env .= "\n$key=$value";
}
file_put_contents($base.'/.env', $env."\n");
chmod($base.'/.env', 0640);
file_put_contents($base.'/.env.testing', "APP_ENV=testing\nAPP_KEY=".$settings['APP_KEY']."\nVENDRA_TEST_PASSWORD=".$passwords['vendra_test']."\nSESSION_DRIVER=array\nCACHE_STORE=array\n");
chmod($base.'/.env.testing', 0600);
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Artisan::call('migrate', ['--database' => 'central', '--force' => true]);
$tenant = Tenant::create(['name' => 'Vexo · Homologação', 'slug' => 'vexo', 'active' => true, 'db_host' => '127.0.0.1', 'db_name' => 'vendra_t_vexo', 'db_username' => 'vendra_vexo', 'db_password' => $passwords['vendra_vexo']]);
app(TenantConnection::class)->connect($tenant);
Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/tenant-migrations', '--force' => true]);
$adminPassword = bin2hex(random_bytes(12));
User::create(['name' => 'Administrador Vexo', 'email' => 'admin@vexo.local', 'password' => $adminPassword, 'role' => 'admin']);
app(TenantConnection::class)->disconnect();
file_put_contents('/root/vendra-primeiro-acesso.txt', "URL: https://2.25.146.67:8445/app/vexo/login\nUsuário: admin@vexo.local\nSenha: $adminPassword\nAltere a senha antes de usar dados reais.\n");
chmod('/root/vendra-primeiro-acesso.txt', 0600);
file_put_contents($base.'/storage/app/provisioned', date(DATE_ATOM));
echo "Bancos exclusivos, empresa Vexo e administrador provisionados. Credenciais em arquivo protegido do administrador.\n";
