<?php

use App\Tenancy\ControlPlane;
use App\Tenancy\OperationalSchemaRelease;
use App\Tenancy\SchemaManifest;
use App\Tenancy\SharedManifestFile;
use App\Tenancy\SharedSchemaBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$privateJournalReady = false;
$applicationBooted = false;

try {
    if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        throw new RuntimeException('root_required');
    }
    $mode = $argv[1] ?? '';
    $journal = $argv[2] ?? '';
    if (!in_array($mode, ['apply', 'rollback'], true) || !preg_match('~^/var/lib/vendra/releases/[a-f0-9]{40}/attempt\.[a-zA-Z0-9]+$~D', $journal)
        || realpath($journal) !== $journal || fileowner($journal)!==0 || (fileperms($journal)&0077)!==0) {
        throw new RuntimeException('private_journal_required');
    }
    $privateJournalReady = true;
    // Privileged DDL never loads PHP from the web process' writable framework caches.
    $frameworkCache = $journal.'/schema-framework';
    if (!is_dir($frameworkCache) && !mkdir($frameworkCache, 0700)) throw new RuntimeException('root_cache_not_created');
    if (is_link($frameworkCache) || realpath($frameworkCache) !== $frameworkCache
        || fileowner($frameworkCache) !== 0 || (fileperms($frameworkCache)&0077)!==0) throw new RuntimeException('root_cache_invalid');
    foreach (['APP_PACKAGES_CACHE'=>'packages.php','APP_SERVICES_CACHE'=>'services.php','APP_CONFIG_CACHE'=>'config.php',
        'APP_EVENTS_CACHE'=>'events.php','APP_ROUTES_CACHE'=>'routes.php','VIEW_COMPILED_PATH'=>''] as $key=>$file) {
        $value = $frameworkCache.($file!==''?'/'.$file:'');
        putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value;
    }
    require __DIR__.'/../vendor/autoload.php';
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $applicationBooted = true;
    $pdo = ControlPlane::pdo();
    $builder = new SharedSchemaBuilder($pdo);
    $database = (string)config('tenancy.shared.database');
    if ($mode === 'rollback') {
        if (!is_file($journal.'/schema.applied')) exit(0);
        $previous = json_decode(file_get_contents($journal.'/schema.previous.json'), true, flags: JSON_THROW_ON_ERROR);
        $current = SharedManifestFile::installed();
        $builder->revertEmptyExtension($database, $previous, $current);
        SharedManifestFile::save($previous);
        unlink($journal.'/schema.applied');
        echo "Estrutura anterior restaurada.\n";
        exit(0);
    }
    $previous = SharedManifestFile::installed();
    $builder->assertExistingSchema($database, $previous);
    if (file_exists($journal.'/schema.previous.json')) throw new RuntimeException('journal_already_used');
    if (file_put_contents($journal.'/schema.previous.json', json_encode($previous, JSON_THROW_ON_ERROR)) === false
        || !chmod($journal.'/schema.previous.json', 0600)) {
        throw new RuntimeException('previous_certificate_not_saved');
    }
    $template = 'vendra_t_test_segments_'.substr(basename(dirname($journal)), 0, 12).'_'.substr(hash('sha256', $journal), 0, 8);
    if (file_put_contents($journal.'/schema.template', $template) !== strlen($template)
        || !chmod($journal.'/schema.template', 0600)) {
        throw new RuntimeException('template_identity_not_journaled');
    }
    if ($pdo->query('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='.$pdo->quote($template))->fetchColumn()) {
        throw new RuntimeException('template_exists');
    }
    $pdo->exec('CREATE DATABASE `'.$template.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `'.$template.'`');
    config(['database.connections.tenant' => ['driver'=>'mysql','database'=>$template,'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'']]);
    DB::purge('tenant');
    $connection = DB::connection('tenant');
    $connection->setPdo($pdo);
    $connection->setReadPdo($pdo);
    if (Artisan::call('migrate', ['--database'=>'tenant','--path'=>'database/tenant-migrations','--force'=>true])!==0) {
        throw new RuntimeException('template_migration_failed');
    }
    $next = SchemaManifest::capture($pdo, $template);
    OperationalSchemaRelease::assertTransition($previous, $next);
    $result = $builder->extend($template, $database, $previous, $next);
    try {
        SharedManifestFile::save($next);
        if (file_put_contents($journal.'/schema.applied', $result['manifest_hash']) !== 64
            || !chmod($journal.'/schema.applied', 0600)) {
            throw new RuntimeException('new_certificate_not_journaled');
        }
    } catch (Throwable) {
        $builder->revertEmptyExtension($database, $previous, $next);
        SharedManifestFile::save($previous);
        if (is_file($journal.'/schema.applied')) unlink($journal.'/schema.applied');
        throw new RuntimeException('private_manifest_save_failed');
    }
    $pdo->exec('DROP DATABASE `'.$template.'`');
    echo 'Estrutura ampliada: '.count($result['tables']).' tabelas. Hash '.$result['manifest_hash'].PHP_EOL;
} catch (Throwable $error) {
    if ($privateJournalReady) {
        $errorFile = $journal.'/schema.error.private.log';
        if (file_put_contents($errorFile, (string)$error.($applicationBooted?"\n".Artisan::output():''), LOCK_EX) !== false) chmod($errorFile, 0600);
    }
    fwrite(STDERR, "Estrutura operacional não concluída. Consulte o journal privado antes de reabrir a aplicação.\n");
    exit(1);
}
