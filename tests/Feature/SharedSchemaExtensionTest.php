<?php

namespace Tests\Feature;

use App\Tenancy\OperationalSchemaRelease;
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
        $additions = OperationalSchemaRelease::profile()['tables'];
        $newMigrations = OperationalSchemaRelease::migrationNames();
        $admin->exec("CREATE DATABASE `$source` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        try {
            config(['database.connections.tenant'=>[
                'driver'=>'mysql', 'unix_socket'=>'/var/run/mysqld/mysqld.sock', 'database'=>$source,
                'username'=>'root', 'password'=>'', 'charset'=>'utf8mb4', 'collation'=>'utf8mb4_unicode_ci', 'strict'=>true,
            ]]);
            DB::purge('tenant');
            mkdir($temporary.'/tenant-migrations', 0700, true);
            foreach (glob($originalPath.'/tenant-migrations/*.php') as $file) {
                if (!in_array(basename($file, '.php'), $newMigrations, true)) {
                    copy($file, $temporary.'/tenant-migrations/'.basename($file));
                }
            }
            $this->app->useDatabasePath($temporary);
            $this->assertSame(0, Artisan::call('migrate', ['--database'=>'tenant','--path'=>$temporary.'/tenant-migrations','--realpath'=>true,'--force'=>true]));
            $previous = SchemaManifest::capture($admin, $source);
            $builder = new SharedSchemaBuilder($admin);
            $builder->build($source, $target, $previous);
            $this->app->useDatabasePath($originalPath);
            $this->assertSame(0, Artisan::call('migrate', ['--database'=>'tenant','--path'=>'database/tenant-migrations','--force'=>true]));
            $next = SchemaManifest::capture($admin, $source);
            OperationalSchemaRelease::assertTransition($previous, $next);
            $outsideRelease = $next;
            $outsideRelease['tables'][] = 'unreviewed_operations';
            try {
                OperationalSchemaRelease::assertTransition($previous, $outsideRelease);
                $this->fail('A allowlist da release deve impedir tabelas extras.');
            } catch (RuntimeException $error) {
                $this->assertSame('schema_extension_outside_reviewed_release', $error->getMessage());
            }
            $admin->exec("ALTER TABLE `$source`.customers ADD COLUMN unreviewed_change INT NULL");
            try {
                $changedSource = SchemaManifest::capture($admin, $source);
                $builder->extend($source, $target, $previous, $changedSource);
                $this->fail('Alterações de DDL legado não são uma expansão aditiva.');
            } catch (RuntimeException $error) {
                $this->assertSame('existing_template_tables_changed', $error->getMessage());
            } finally {
                $admin->exec("ALTER TABLE `$source`.customers DROP COLUMN unreviewed_change");
            }
            $builder->assertExistingSchema($target, $previous);
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
            $this->assertEqualsCanonicalizing($additions, $result['tables']);
            $newUser = 'vxs_0000000000000003_'.$suffix;
            $builder->registerRuntime($target, 3, $newUser, $password);
            $newTenant = new PDO("mysql:host=127.0.0.1;dbname=$target", $newUser, $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            foreach ($additions as $table) {
                $this->assertSame(0, (int)$newTenant->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn());
            }
            $this->assertSame(SharedSchemaBuilder::manifestHash($next), $newTenant->query('SELECT schema_hash FROM __vendra_identity')->fetchColumn());
            $this->assertSame('Cliente anterior', $a->query('SELECT name FROM customers')->fetchColumn());
            $this->assertSame(0, (int)$b->query('SELECT COUNT(*) FROM customers')->fetchColumn());
            $products = [];
            foreach ([$a, $b] as $runtime) {
                $runtime->exec("INSERT INTO product_groups(name) VALUES ('Grupo compartilhado QA')");
                $groupId = (int)$runtime->lastInsertId();
                $runtime->exec("INSERT INTO products(product_group_id,name,sku,sale_price) VALUES ($groupId,'Produto anterior QA','SAME-SKU','10.00')");
                $products[] = (int)$runtime->lastInsertId();
                $runtime->exec("INSERT INTO delivery_integrations(provider) VALUES ('ifood')");
            }
            // Unique providers may repeat across namespaces; physical tables are never granted.
            $this->assertSame(1, (int)$a->query('SELECT COUNT(*) FROM delivery_integrations')->fetchColumn());
            $this->assertSame(1, (int)$b->query('SELECT COUNT(*) FROM delivery_integrations')->fetchColumn());
            $a->exec("INSERT INTO restaurant_modifier_groups(product_id,name) VALUES ({$products[0]},'Acompanhamentos')");
            $this->assertSame(0, (int)$b->query('SELECT COUNT(*) FROM restaurant_modifier_groups')->fetchColumn());
            try {
                $a->exec("INSERT INTO restaurant_modifier_groups(product_id,name) VALUES ({$products[1]},'Produto de outra loja')");
                $this->fail('Uma FK nova não pode apontar para o produto de outra empresa.');
            } catch (\PDOException $error) {
                $this->assertSame('23000', $error->getCode());
            }
            try {
                $a->query('SELECT COUNT(*) FROM _p_delivery_integrations');
                $this->fail('O runtime não deve ler a tabela física nova.');
            } catch (\PDOException $error) {
                $this->assertSame('42000', $error->getCode());
            }
            $b->exec("INSERT INTO restaurant_modifier_groups(product_id,name) VALUES ({$products[1]},'Acompanhamentos')");
            foreach ([1, 2, 3] as $tenantId) $builder->assertTenantIntegrity($target, $tenantId, $next);
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
            foreach ([$a, $b] as $runtime) {
                $runtime->exec('DELETE FROM restaurant_modifier_groups');
                $runtime->exec('DELETE FROM delivery_integrations');
            }
            $builder->revertEmptyExtension($target, $previous, $next);
            $builder->assertExistingSchema($target, $previous);
            $this->assertSame(SharedSchemaBuilder::manifestHash($previous), $a->query('SELECT schema_hash FROM __vendra_identity')->fetchColumn());
            $this->assertSame('Cliente anterior', $a->query('SELECT name FROM customers')->fetchColumn());
            $this->assertSame(0, (int)$b->query('SELECT COUNT(*) FROM customers')->fetchColumn());
        } finally {
            $this->app->useDatabasePath($originalPath);
            DB::purge('tenant');
            foreach ([...$users, 'vxs_0000000000000003_'.$suffix] as $user) {
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
