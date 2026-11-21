<?php

namespace Tests\Feature;

use App\Tenancy\SchemaManifest;
use App\Tenancy\SharedSchemaBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Tests\TestCase;

final class SharedSchemaExtensionTest extends TestCase
{
    public function test_additive_upgrade_preserves_namespaces_and_reverts_empty_tables_on_failure(): void
    {
        if (!env('VENDRA_TEST_PASSWORD')) {
            $this->markTestSkipped('Configure o MySQL dedicado de testes.');
        }
        $admin = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $suffix = bin2hex(random_bytes(4));
        $source = 'vendra_t_test_schemaext_'.$suffix;
        $target = 'vendra_shared_qa_ext_'.$suffix;
        $originalPath = database_path();
        $temporary = sys_get_temp_dir().'/vendra-old-migrations-'.$suffix;
        $users = ['vxs_0000000000000001_'.$suffix, 'vxs_0000000000000002_'.$suffix];
        $password = str_repeat('Q', 48);
        $definer = 'vxd_'.substr(hash('sha256', $target), 0, 24);
        $additions = ['restaurant_order_items', 'restaurant_orders', 'restaurant_tables', 'supermarket_carts'];
        $newMigrations = ['2026_11_21_000002_create_supermarket_carts', '2026_11_21_000101_create_restaurant_operations'];
        $admin->exec("CREATE DATABASE `$source` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        try {
            config(['database.connections.tenant'=>[
                'driver'=>'mysql', 'unix_socket'=>'/var/run/mysqld/mysqld.sock', 'database'=>$source,
                'username'=>'root', 'password'=>'', 'charset'=>'utf8mb4', 'collation'=>'utf8mb4_unicode_ci', 'strict'=>true,
            ]]);
            DB::purge('tenant');
            $this->assertSame(0, Artisan::call('migrate', ['--database'=>'tenant','--path'=>'database/tenant-migrations','--force'=>true]));
            foreach ($additions as $table) {
                $admin->exec("DROP TABLE `$source`.`$table`");
            }
            $delete = $admin->prepare("DELETE FROM `$source`.migrations WHERE migration IN (?,?)");
            $delete->execute($newMigrations);
            mkdir($temporary.'/tenant-migrations', 0700, true);
            foreach (glob($originalPath.'/tenant-migrations/*.php') as $file) {
                if (!in_array(basename($file, '.php'), $newMigrations, true)) {
                    copy($file, $temporary.'/tenant-migrations/'.basename($file));
                }
            }
            $this->app->useDatabasePath($temporary);
            $previous = SchemaManifest::capture($admin, $source);
            $builder = new SharedSchemaBuilder($admin);
            $builder->build($source, $target, $previous);
            $this->app->useDatabasePath($originalPath);
            $this->assertSame(0, Artisan::call('migrate', ['--database'=>'tenant','--path'=>'database/tenant-migrations','--force'=>true]));
            $next = SchemaManifest::capture($admin, $source);
            foreach ($users as $index=>$user) {
                $builder->registerRuntime($target, $index+1, $user, $password);
            }
            $a = new PDO("mysql:host=127.0.0.1;dbname=$target", $users[0], $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $b = new PDO("mysql:host=127.0.0.1;dbname=$target", $users[1], $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $a->exec("INSERT INTO customers(name) VALUES ('Cliente anterior')");
            // An unexpected existing privilege forces the final assertion to fail after DDL.
            $admin->exec("GRANT SELECT ON `$source`.customers TO '{$users[0]}'@'127.0.0.1'");
            try {
                $builder->extend($source, $target, $previous, $next);
                $this->fail('Uma concessão não certificada deve impedir a ampliação.');
            } catch (RuntimeException $error) {
                $this->assertSame('shared_schema_extension_failed_reverted', $error->getMessage());
            }
            $builder->assertExistingSchema($target, $previous);
            $admin->exec("REVOKE SELECT ON `$source`.customers FROM '{$users[0]}'@'127.0.0.1'");
            $result = $builder->extend($source, $target, $previous, $next);
            $this->assertCount(4, $result['tables']);
            $this->assertSame('Cliente anterior', $a->query('SELECT name FROM customers')->fetchColumn());
            $this->assertSame(0, (int)$b->query('SELECT COUNT(*) FROM customers')->fetchColumn());
            $a->exec("INSERT INTO restaurant_tables(name) VALUES ('Mesa 1')");
            $this->assertSame(0, (int)$b->query('SELECT COUNT(*) FROM restaurant_tables')->fetchColumn());
            $b->exec("INSERT INTO restaurant_tables(name) VALUES ('Mesa 1')");
            $this->assertSame(1, (int)$a->query('SELECT COUNT(*) FROM restaurant_tables')->fetchColumn());
            foreach ([$a, $b] as $runtime) {
                $this->assertSame(SharedSchemaBuilder::manifestHash($next), $runtime->query('SELECT schema_hash FROM __vendra_identity')->fetchColumn());
            }
            try {
                $builder->revertEmptyExtension($target, $previous, $next);
                $this->fail('Uma tabela com dados operacionais exige reconciliação.');
            } catch (RuntimeException $error) {
                $this->assertSame('extension_contains_data_requires_reconciliation', $error->getMessage());
            }
            $builder->assertExistingSchema($target, $next);
            $a->exec('DELETE FROM restaurant_tables');
            $b->exec('DELETE FROM restaurant_tables');
            $builder->revertEmptyExtension($target, $previous, $next);
            $builder->assertExistingSchema($target, $previous);
            $this->assertSame(SharedSchemaBuilder::manifestHash($previous), $a->query('SELECT schema_hash FROM __vendra_identity')->fetchColumn());
            $this->assertSame('Cliente anterior', $a->query('SELECT name FROM customers')->fetchColumn());
            $this->assertSame(0, (int)$b->query('SELECT COUNT(*) FROM customers')->fetchColumn());
        } finally {
            $this->app->useDatabasePath($originalPath);
            DB::purge('tenant');
            foreach ($users as $user) {
                $admin->exec("DROP USER IF EXISTS '$user'@'127.0.0.1'");
            }
            $admin->exec("DROP USER IF EXISTS '$definer'@'localhost'");
            $admin->exec("DROP DATABASE IF EXISTS `$target`");
            $admin->exec("DROP DATABASE IF EXISTS `$source`");
            foreach (glob($temporary.'/tenant-migrations/*.php') as $file) unlink($file);
            if (is_dir($temporary.'/tenant-migrations')) rmdir($temporary.'/tenant-migrations');
            if (is_dir($temporary)) rmdir($temporary);
        }
    }
}
