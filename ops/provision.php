<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantConnection;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

// Run once, from CLI as the VPS administrator. No credentials are printed.
if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    fwrite(STDERR, "Execute como administrador no terminal.\n");
    exit(1);
}
umask(0077);
$lock = fopen('/run/lock/vendra-provision.lock', 'c');
if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Outro provisionamento está em andamento.\n");
    exit(1);
}
$base = dirname(__DIR__);
if (! in_array('--fresh-instance', $argv, true)) {
    fwrite(STDERR, "Use --fresh-instance apenas numa instalação nova e revisada.\n");
    exit(1);
}

if (file_exists($base.'/storage/app/provisioned')) {
    fwrite(STDERR, "Instância já provisionada; nenhum recurso alterado.\n");
    exit(1);
}
foreach ([$base.'/.env', $base.'/.env.testing', '/root/vendra-provisioning.json', '/root/vendra-primeiro-acesso.txt'] as $existing) {
    if (file_exists($existing) || is_link($existing)) {
        fwrite(STDERR, "Arquivo de configuração/recuperação preexistente; preserve e revise antes de provisionar.\n");
        exit(1);
    }
}
require $base.'/vendor/autoload.php';
try {
    $pdo = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $passwords = [];
    foreach (['vendra_central' => 'vendra_central', 'vendra_t_vexo' => 'vendra_vexo', 'vendra_test_central' => 'vendra_test', 'vendra_t_test_a' => 'vendra_test', 'vendra_t_test_b' => 'vendra_test'] as $db => $user) {
        $passwords[$user] ??= bin2hex(random_bytes(24));
        // Never adopt existing databases/accounts: fail closed instead of changing their passwords.
        if ($pdo->query('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='.$pdo->quote($db))->fetchColumn()) {
            fwrite(STDERR, "Banco reservado já existe: $db. Inspecione antes de continuar.\n");
            exit(1);
        }
    }
    foreach ($passwords as $user => $password) {
        $exists = $pdo->query('SELECT COUNT(*) FROM mysql.user WHERE User='.$pdo->quote($user)." AND Host='127.0.0.1'")->fetchColumn();
        if ($exists) {
            fwrite(STDERR, "Usuário reservado já existe: $user.\n");
            exit(1);
        }
    }
    $appKey = 'base64:'.base64_encode(random_bytes(32));
    $adminPassword = bin2hex(random_bytes(12));
    // Persist recovery material before the first nontransactional MySQL DDL.
    $journal = '/root/vendra-provisioning.json';
    if (file_put_contents($journal, json_encode(['status' => 'started', 'created_at' => date(DATE_ATOM), 'database_passwords' => $passwords, 'app_key' => $appKey, 'initial_admin_password' => $adminPassword], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Cannot persist recovery journal.');
    }
    chmod($journal, 0600);
    foreach ($passwords as $user => $password) {
        $pdo->exec('CREATE USER '.$pdo->quote($user)."@'127.0.0.1' IDENTIFIED BY ".$pdo->quote($password));
    }
    foreach (['vendra_central' => 'vendra_central', 'vendra_t_vexo' => 'vendra_vexo', 'vendra_test_central' => 'vendra_test', 'vendra_t_test_a' => 'vendra_test', 'vendra_t_test_b' => 'vendra_test'] as $db => $user) {
        $pdo->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("GRANT ALL PRIVILEGES ON `$db`.* TO ".$pdo->quote($user)."@'127.0.0.1'");
    }
    $env = file_get_contents($base.'/.env.example');
    $settings = ['APP_NAME' => 'Vendra', 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_KEY' => $appKey, 'APP_URL' => 'https://2.25.146.67:8445', 'APP_LOCALE' => 'pt_BR', 'DB_CONNECTION' => 'central', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'vendra_central', 'DB_USERNAME' => 'vendra_central', 'DB_PASSWORD' => $passwords['vendra_central'], 'SESSION_DRIVER' => 'file', 'SESSION_SECURE_COOKIE' => 'true', 'SESSION_COOKIE' => 'vendra_session', 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync', 'LOG_LEVEL' => 'warning', 'LOG_CHANNEL' => 'daily', 'LOG_DAILY_DAYS' => '14'];
    foreach ($settings as $key => $value) {
        $env = preg_replace('/^#?\s*'.preg_quote($key, '/').'=.*/m', '', $env);
        $env .= "\n$key=$value";
    }
    if (file_put_contents($base.'/.env', $env."\n") === false) {
        throw new RuntimeException('Cannot persist environment.');
    }
    chmod($base.'/.env', 0640);
    if (file_put_contents($base.'/.env.testing', "APP_ENV=testing\nAPP_KEY=".$settings['APP_KEY']."\nVENDRA_TEST_PASSWORD=".$passwords['vendra_test']."\nSESSION_DRIVER=array\nCACHE_STORE=array\n") === false) {
        throw new RuntimeException('Cannot persist test environment.');
    }
    chmod($base.'/.env.testing', 0600);
    $app = require $base.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (Artisan::call('migrate', ['--database' => 'central', '--force' => true]) !== 0) {
        throw new RuntimeException('Central migration failed.');
    }
    $tenant = Tenant::create(['name' => 'Vexo · Homologação', 'slug' => 'vexo', 'active' => true, 'db_host' => '127.0.0.1', 'db_name' => 'vendra_t_vexo', 'db_username' => 'vendra_vexo', 'db_password' => $passwords['vendra_vexo']]);
    app(TenantConnection::class)->connect($tenant);
    if (Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/tenant-migrations', '--force' => true]) !== 0) {
        throw new RuntimeException('Tenant migration failed.');
    }
    User::create(['name' => 'Administrador Vexo', 'email' => 'admin@vexo.local', 'password' => $adminPassword, 'role' => 'admin']);
    app(TenantConnection::class)->disconnect();
    if (file_put_contents('/root/vendra-primeiro-acesso.txt', "URL: https://2.25.146.67:8445/app/vexo/login\nUsuário: admin@vexo.local\nSenha: $adminPassword\nAltere a senha antes de usar dados reais.\n") === false) {
        throw new RuntimeException('Cannot persist first access.');
    }
    chmod('/root/vendra-primeiro-acesso.txt', 0600);
    if (file_put_contents($base.'/storage/app/provisioned', date(DATE_ATOM)) === false) {
        throw new RuntimeException('Cannot persist provision marker.');
    }
    $completedJournal = json_decode(file_get_contents($journal), true, 512, JSON_THROW_ON_ERROR);
    $completedJournal['status'] = 'completed';
    $completedJournal['completed_at'] = date(DATE_ATOM);
    if (file_put_contents($journal, json_encode($completedJournal, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Cannot update journal.');
    }
    echo "Bancos exclusivos, empresa Vexo e administrador provisionados. Credenciais em arquivo protegido do administrador.\n";

} catch (Throwable $exception) {
    // Do not print SQL, credentials, exception arguments or environment contents.
    fwrite(STDERR, "Provisionamento interrompido. Não repita nem remova bancos automaticamente. Revise o journal privado /root/vendra-provisioning.json e os recursos criados.\n");
    exit(1);
} finally {
    if (isset($app)) {
        app(TenantConnection::class)->disconnect();
    }
    flock($lock, LOCK_UN);
    fclose($lock);
}
