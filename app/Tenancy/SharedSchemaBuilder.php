<?php

namespace App\Tenancy;

use PDO;
use RuntimeException;

/** Privileged, offline schema construction. Runtime users receive privileges on views only. */
final class SharedSchemaBuilder
{
    public const VERSION = 1;
    public const SINGLETONS = ['business_profiles','fiscal_settings','company_operations','cash_closure_locks'];
    private const SCOPE = '_scope_tenant_id';

    public function __construct(private PDO $admin)
    {
        $this->cli();
        $admin->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $admin->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function build(string $template, string $target, array $manifest): array
    {
        $this->cli(); $this->database($target);
        if (! preg_match('/^vendra_(?:t_test_|shared_template_)[a-z0-9_]+$/D', $template) || $template === $target) { $this->fail('template_rejected'); }
        $tables = $this->tableNames($manifest);
        SchemaManifest::assertTemplate($this->admin,$template,$manifest);
        if ($this->rows('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?',[$target])) { $this->fail('target_must_be_new'); }
        if ((int)$this->admin->query("SELECT COUNT(*) FROM mysql.user WHERE User=''")->fetchColumn() !== 0) { $this->fail('anonymous_mysql_accounts_not_allowed'); }
        $db = $this->id($target); $definer = 'vxd_'.substr(hash('sha256',$target),0,24); $account = $this->account($definer,'localhost');
        $this->admin->exec("CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        try {
            $this->admin->exec('CREATE USER '.$account.' IDENTIFIED BY '.$this->admin->quote(bin2hex(random_bytes(32))).' ACCOUNT LOCK');
            $this->admin->exec("CREATE TABLE $db._vendra_schema_manifest (id TINYINT UNSIGNED PRIMARY KEY,version INT NOT NULL,manifest_hash CHAR(64) NOT NULL,guard_hash CHAR(64) NOT NULL,body JSON NOT NULL,definer_login VARCHAR(32) NOT NULL)");
            $this->admin->exec("CREATE TABLE $db._vendra_principals (login VARBINARY(32) PRIMARY KEY,tenant_id BIGINT UNSIGNED NOT NULL,kind VARCHAR(10) NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)");
            foreach ($tables as $table) { $this->cloneTable($template,$target,$table); }
            $foreignKeys = $this->foreignKeys($template);
            foreach ($foreignKeys as $fk) { $this->createForeignKey($target,$fk,$tables); }
            foreach ($tables as $table) { $this->guardsAndView($target,$table,$account); }
            $scope = $this->scopeExpression(); $schemaHash = $this->admin->quote(self::manifestHash($manifest)); $policyHash = $this->admin->quote($manifest['guard_policy_sha256']);
            $this->admin->exec("CREATE ALGORITHM=MERGE DEFINER=$account SQL SECURITY DEFINER VIEW $db.__vendra_identity AS SELECT $scope AS tenant_id,$schemaHash AS schema_hash,$policyHash AS guard_policy_hash WHERE $scope>0");
            $guardHash = $this->guardHash($target);
            $statement = $this->admin->prepare("INSERT INTO $db._vendra_schema_manifest VALUES(1,?,?,?,?,?)");
            $statement->execute([self::VERSION,$this->hash($manifest),$guardHash,json_encode($manifest,JSON_THROW_ON_ERROR),$definer]);
            $this->assertExistingSchema($target,$manifest);
            return ['database'=>$target,'tables'=>$tables,'manifest_hash'=>$this->hash($manifest),'guard_hash'=>$guardHash,'foreign_keys'=>$foreignKeys,'definer'=>$definer];
        } catch (\Throwable) {
            // Keep a partial NEW schema for privileged diagnosis; never drop an existing database.
            throw new RuntimeException('shared_schema_build_failed');
        }
    }

    /** Add certified empty tables without replacing existing business data or runtime credentials. */
    public function extend(string $template, string $target, array $previous, array $next): array
    {
        $this->assertExistingSchema($target, $previous);
        if (($previous['guard_policy_sha256'] ?? null) !== ($next['guard_policy_sha256'] ?? null)) {
            $this->fail('additive_extension_cannot_change_guard_policy');
        }
        SchemaManifest::assertTemplate($this->admin, $template, $next);
        $oldTables = $this->tableNames($previous);
        $newTables = array_values(array_diff($this->tableNames($next), $oldTables));
        if (!$newTables || array_diff($oldTables, $this->tableNames($next))) {
            $this->fail('extension_must_only_add_tables');
        }
        foreach ($previous['migrations'] as $name => $digest) {
            if (($next['migrations'][$name] ?? null) !== $digest) {
                $this->fail('existing_migration_changed');
            }
        }
        $sourceSchema = [];
        foreach ($oldTables as $table) {
            $row = $this->admin->query('SHOW CREATE TABLE '.$this->id($template).'.'.$this->id($table))->fetch(PDO::FETCH_NUM);
            $ddl = preg_replace('/ AUTO_INCREMENT=\d+\b/', '', $row[1]);
            $sourceSchema[$table] = str_replace('`'.$template.'`.', '', $ddl);
        }
        $fingerprint = hash('sha256', json_encode($sourceSchema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if (!hash_equals($previous['source_fingerprint'], $fingerprint)) {
            $this->fail('existing_template_tables_changed');
        }
        $db = $this->id($target);
        $metadata = $this->rows("SELECT * FROM $db._vendra_schema_manifest WHERE id=1")[0];
        $definer = $this->account($metadata['definer_login'], 'localhost');
        $principals = $this->rows("SELECT login,kind FROM $db._vendra_principals ORDER BY login");
        foreach ($newTables as $table) {
            if ($this->rows('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN (?,?)', [$target, $table, '_p_'.$table])) {
                $this->fail('extension_object_already_exists');
            }
        }
        $created = [];
        try {
            foreach ($newTables as $table) {
                $created[] = $table;
                $this->cloneTable($template, $target, $table);
            }
            foreach ($this->foreignKeys($template) as $fk) {
                if (in_array($fk['table'], $newTables, true)) {
                    $this->createForeignKey($target, $fk, $this->tableNames($next));
                }
            }
            foreach ($newTables as $table) {
                $this->guardsAndView($target, $table, $definer);
                foreach ($principals as $principal) {
                    $this->admin->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON '.$db.'.'.$this->id($table).' TO '.$this->account($principal['login'], '127.0.0.1'));
                }
            }
            $this->replaceIdentity($target, $definer, $next);
            $update = $this->admin->prepare("UPDATE $db._vendra_schema_manifest SET manifest_hash=?,guard_hash=?,body=? WHERE id=1");
            $update->execute([$this->hash($next), $this->guardHash($target), json_encode($next, JSON_THROW_ON_ERROR)]);
            $this->assertExistingSchema($target, $next);
            foreach ($principals as $principal) {
                $this->assertGrants($target, $principal['login'], '127.0.0.1', $principal['kind'], $next, true);
            }
            return ['tables' => $newTables, 'manifest_hash' => $this->hash($next), 'guard_hash' => $this->guardHash($target)];
        } catch (\Throwable $error) {
            $this->removeEmptyExtension($target, $created, $principals, $metadata['definer_login']);
            $this->replaceIdentity($target, $definer, $previous);
            $restore = $this->admin->prepare("UPDATE $db._vendra_schema_manifest SET manifest_hash=?,guard_hash=?,body=? WHERE id=1");
            $restore->execute([$metadata['manifest_hash'], $metadata['guard_hash'], $metadata['body']]);
            $this->assertExistingSchema($target, $previous);
            throw new RuntimeException('shared_schema_extension_failed_reverted');
        }
    }

    /** Restore the preceding certificate only while the added tables are still empty. */
    public function revertEmptyExtension(string $target, array $previous, array $current): void
    {
        $this->assertExistingSchema($target, $current);
        $newTables = array_values(array_diff($this->tableNames($current), $this->tableNames($previous)));
        if (!$newTables || array_diff($this->tableNames($previous), $this->tableNames($current))) {
            $this->fail('invalid_extension_reversal');
        }
        $db = $this->id($target);
        $metadata = $this->rows("SELECT definer_login FROM $db._vendra_schema_manifest WHERE id=1")[0];
        $principals = $this->rows("SELECT login,kind FROM $db._vendra_principals ORDER BY login");
        $this->removeEmptyExtension($target, $newTables, $principals, $metadata['definer_login']);
        $this->replaceIdentity($target, $this->account($metadata['definer_login'], 'localhost'), $previous);
        $restore = $this->admin->prepare("UPDATE $db._vendra_schema_manifest SET manifest_hash=?,guard_hash=?,body=? WHERE id=1");
        $restore->execute([$this->hash($previous), $this->guardHash($target), json_encode($previous, JSON_THROW_ON_ERROR)]);
        $this->assertExistingSchema($target, $previous);
    }

    private function revokeTableGrants(string $target, string $table, string $username, string $host): void
    {
        $grantee = "'".$username."'@'".$host."'";
        if ($this->rows('SELECT PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE=? AND TABLE_SCHEMA=? AND TABLE_NAME=?', [$grantee, $target, $table])) {
            $this->admin->exec('REVOKE ALL PRIVILEGES ON '.$this->id($target).'.'.$this->id($table).' FROM '.$this->account($username, $host));
        }
    }

    private function replaceIdentity(string $target, string $definer, array $manifest): void
    {
        $scope = $this->scopeExpression();
        $schema = $this->admin->quote($this->hash($manifest));
        $policy = $this->admin->quote($manifest['guard_policy_sha256']);
        $this->admin->exec('CREATE OR REPLACE ALGORITHM=MERGE DEFINER='.$definer.' SQL SECURITY DEFINER VIEW '.$this->id($target).'.__vendra_identity AS SELECT '.$scope.' AS tenant_id,'.$schema.' AS schema_hash,'.$policy.' AS guard_policy_hash WHERE '.$scope.'>0');
    }

    private function removeEmptyExtension(string $target, array $tables, array $principals, string $definer): void
    {
        $db = $this->id($target);
        $tables = array_values(array_filter($tables, fn ($table) => $this->rows('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [$target, '_p_'.$table]) !== []));
        foreach ($tables as $table) {
            if ((int)$this->admin->query('SELECT COUNT(*) FROM '.$db.'.'.$this->id('_p_'.$table))->fetchColumn() !== 0) {
                $this->fail('extension_contains_data_requires_reconciliation');
            }
        }
        foreach ($this->foreignKeys($target, true) as $fk) {
            if (in_array(substr($fk['table'], 3), $tables, true)) {
                $this->admin->exec('ALTER TABLE '.$db.'.'.$this->id($fk['table']).' DROP FOREIGN KEY '.$this->id($fk['name']));
            }
        }
        foreach (array_reverse($tables) as $table) {
            foreach ($principals as $principal) {
                $this->revokeTableGrants($target, $table, $principal['login'], '127.0.0.1');
            }
            $this->revokeTableGrants($target, '_p_'.$table, $definer, 'localhost');
            $this->admin->exec('DROP VIEW IF EXISTS '.$db.'.'.$this->id($table));
            $this->admin->exec('DROP TABLE '.$db.'.'.$this->id('_p_'.$table));
        }
    }

    public function assertExistingSchema(string $target, array $manifest): void
    {
        $this->cli(); $this->database($target); $this->tableNames($manifest);
        $row = $this->certifiedMetadata($target,$manifest);
        $state = $this->rows('SELECT account_locked FROM mysql.user WHERE User=? AND Host=\'localhost\'',[$row['definer_login']]);
        if (count($state)!==1 || $state[0]['account_locked']!=='Y') { $this->fail('definer_must_be_locked'); }
    }

    /** Restore only the missing locked definer; table/view/trigger definitions stay immutable. */
    public function restoreDefiner(string $target, array $manifest): void
    {
        $row=$this->certifiedMetadata($target,$manifest);$username=$row['definer_login'];
        if($username!=='vxd_'.substr(hash('sha256',$target),0,24)){$this->fail('definer_identity_invalid');}
        $state=$this->rows('SELECT account_locked FROM mysql.user WHERE User=? AND Host=\'localhost\'',[$username]);
        $exists=$state!==[];
        if($exists&&($state[0]['account_locked']!=='Y'||count($state)!==1)){$this->fail('existing_definer_not_expected_locked_account');}
        if($exists){$this->assertGrants($target,$username,'localhost','definer',$manifest,false);}
        $account=$this->account($username,'localhost');
        if(!$exists){$this->admin->exec('CREATE USER '.$account.' IDENTIFIED BY '.$this->admin->quote(bin2hex(random_bytes(32))).' ACCOUNT LOCK');}
        foreach($this->tableNames($manifest)as$table){$this->admin->exec('GRANT SELECT,INSERT,UPDATE,DELETE,TRIGGER ON '.$this->id($target).'.'.$this->id('_p_'.$table).' TO '.$account);}
        $this->assertGrants($target,$username,'localhost','definer',$manifest,true);
        $this->assertExistingSchema($target,$manifest);
    }

    /** Registry ownership and central password are preserved; never rotates or replaces an existing login. */
    public function restoreRuntime(string $target,int $tenantId,string $username,#[\SensitiveParameter]string $password,array $manifest): void
    {
        $this->assertExistingSchema($target,$manifest);$this->username($username);
        if($tenantId<1||!str_starts_with($username,'vxs_'.sprintf('%016x',$tenantId).'_')){$this->fail('restore_runtime_identity_invalid');}
        $registry=$this->rows('SELECT tenant_id,kind FROM '.$this->id($target).'._vendra_principals WHERE login=?',[$username]);
        if(count($registry)!==1||(int)$registry[0]['tenant_id']!==$tenantId||$registry[0]['kind']!=='runtime'){$this->fail('restore_registry_mismatch');}
        $state=$this->rows('SELECT account_locked FROM mysql.user WHERE User=? AND Host=\'127.0.0.1\'',[$username]);
        if($state){
            if(count($state)!==1||$state[0]['account_locked']!=='N'){$this->fail('existing_runtime_account_unexpected');}
            $this->assertGrants($target,$username,'127.0.0.1','runtime',$manifest,true);
            $this->verifyRuntimePassword($target,$tenantId,$username,$password,$manifest);
            return;
        }
        if(!preg_match('/^[a-zA-Z0-9]{32,128}$/D',$password)){$this->fail('restore_runtime_password_invalid');}
        $account=$this->account($username,'127.0.0.1');$created=false;
        try{
            $this->admin->exec('CREATE USER '.$account.' IDENTIFIED BY '.$this->admin->quote($password));$created=true;
            foreach($this->tableNames($manifest)as$table){$this->admin->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON '.$this->id($target).'.'.$this->id($table).' TO '.$account);}
            $this->admin->exec('GRANT SELECT ON '.$this->id($target).'.__vendra_identity TO '.$account);
            $this->assertGrants($target,$username,'127.0.0.1','runtime',$manifest,true);
            $this->verifyRuntimePassword($target,$tenantId,$username,$password,$manifest);
        }catch(\Throwable){if($created){$this->admin->exec('DROP USER IF EXISTS '.$account);}throw new RuntimeException('restore_runtime_failed_no_data_changed');}
    }

    public function registerRuntime(string $target, int $tenantId, string $username, #[\SensitiveParameter] string $password): void
    {
        $this->principal($target,$tenantId,$username,$password,'runtime');
    }

    public function createImport(string $target, int $tenantId, string $username, #[\SensitiveParameter] string $password): void
    {
        $this->principal($target,$tenantId,$username,$password,'import');
    }

    public function dropPrincipal(string $target, string $username): void
    {
        $this->cli(); $this->database($target); $this->username($username);
        $row=$this->rows('SELECT login FROM '.$this->id($target).'._vendra_principals WHERE login=?',[$username]);
        if (count($row)!==1) { $this->fail('principal_not_registered_here'); }
        $this->admin->exec('DROP USER IF EXISTS '.$this->account($username,'127.0.0.1'));
        $query=$this->admin->prepare('DELETE FROM '.$this->id($target).'._vendra_principals WHERE login=?'); $query->execute([$username]);
    }

    public function assertTenantIntegrity(string $target, int $tenantId, array $manifest): void
    {
        $this->assertExistingSchema($target,$manifest);
        if ($tenantId<1) { $this->fail('tenant_id_invalid'); }
        foreach ($this->foreignKeys($target,true) as $fk) {
            $child=$this->id($target).'.'.$this->id($fk['table']); $parent=$this->id($target).'.'.$this->id($fk['parent']);
            $joins=[]; $present=[];
            foreach ($fk['columns'] as $i=>$column) { $joins[]='c.'.$this->id($column).'=p.'.$this->id($fk['referenced_columns'][$i]); if ($column!==self::SCOPE) { $present[]='c.'.$this->id($column).' IS NOT NULL'; } }
            $sql="SELECT COUNT(*) FROM $child c LEFT JOIN $parent p ON ".implode(' AND ',$joins).' WHERE c.'.self::SCOPE.'=? AND '.implode(' AND ',$present).' AND p.'.self::SCOPE.' IS NULL';
            $query=$this->admin->prepare($sql);$query->execute([$tenantId]);if((int)$query->fetchColumn()!==0){$this->fail('tenant_foreign_key_integrity_failed');}
        }
    }

    public function namespaceCounts(string $target, int $tenantId, array $manifest): array
    {
        $this->assertExistingSchema($target,$manifest);
        if($tenantId<1){$this->fail('tenant_id_invalid');}
        $counts=[];
        foreach($this->tableNames($manifest)as$table){$query=$this->admin->prepare('SELECT COUNT(*) FROM '.$this->id($target).'.'.$this->id('_p_'.$table).' WHERE '.self::SCOPE.'=?');$query->execute([$tenantId]);$counts[$table]=(int)$query->fetchColumn();}
        return $counts;
    }

    public function assertEmptyNamespace(string $target, int $tenantId, array $manifest): void
    {
        if(array_sum($this->namespaceCounts($target,$tenantId,$manifest))!==0){$this->fail('tenant_namespace_not_empty');}
    }

    public static function manifestHash(array $manifest): string
    {
        $sort=function(array $v)use(&$sort):array{if(!array_is_list($v)){ksort($v);}foreach($v as&$item){if(is_array($item)){$item=$sort($item);}}return $v;};
        return hash('sha256',json_encode($sort($manifest),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }

    private function certifiedMetadata(string $target,array $manifest): array
    {
        $this->cli();$this->database($target);$this->tableNames($manifest);
        $row=$this->rows('SELECT version,manifest_hash,guard_hash,definer_login FROM '.$this->id($target).'._vendra_schema_manifest WHERE id=1')[0]??null;
        if(!$row||(int)$row['version']!==self::VERSION||!hash_equals($row['manifest_hash'],$this->hash($manifest))||!hash_equals($row['guard_hash'],$this->guardHash($target))){$this->fail('shared_schema_not_certified_or_drifted');}
        return $row;
    }

    private function verifyRuntimePassword(string $target,int $tenantId,string $username,#[\SensitiveParameter]string $password,array $manifest): void
    {
        try{
            $port=(int)config('tenancy.shared.port',3306);
            $pdo=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname='.$target.';charset=utf8mb4',$username,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
            $rows=$pdo->query('SELECT tenant_id,schema_hash,guard_policy_hash FROM __vendra_identity')->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)!==1||(int)$rows[0]['tenant_id']!==$tenantId||!hash_equals(self::manifestHash($manifest),(string)$rows[0]['schema_hash'])||!hash_equals($manifest['guard_policy_sha256'],(string)$rows[0]['guard_policy_hash'])||$pdo->query('SELECT @@server_uuid')->fetchColumn()!==$this->admin->query('SELECT @@server_uuid')->fetchColumn()){$this->fail('restored_runtime_identity_invalid');}
        }catch(\Throwable){$this->fail('existing_or_restored_runtime_not_verified');}
    }

    private function assertGrants(string $target,string $username,string $host,string $kind,array $manifest,bool $complete): void
    {
        $grantee="'".$username."'@'".$host."'";
        foreach(['USER_PRIVILEGES','SCHEMA_PRIVILEGES','COLUMN_PRIVILEGES']as$table){
            $query='SELECT COUNT(*) AS n FROM information_schema.'.$table.' WHERE GRANTEE=?'.($table==='USER_PRIVILEGES'?" AND (PRIVILEGE_TYPE<>'USAGE' OR IS_GRANTABLE<>'NO')":'');
            if((int)($this->rows($query,[$grantee])[0]['n']??0)!==0){$this->fail('principal_has_unexpected_privileges');}
        }
        foreach(['SELECT COUNT(*) AS n FROM mysql.role_edges WHERE TO_USER=? AND TO_HOST=?','SELECT COUNT(*) AS n FROM mysql.proxies_priv WHERE User=? AND Host=?','SELECT COUNT(*) AS n FROM mysql.procs_priv WHERE User=? AND Host=?']as$sql){if((int)($this->rows($sql,[$username,$host])[0]['n']??0)!==0){$this->fail('principal_has_unexpected_roles_or_routines');}}
        $expected=[];
        foreach($this->tableNames($manifest)as$table){foreach($kind==='definer'?['SELECT','INSERT','UPDATE','DELETE','TRIGGER']:['SELECT','INSERT','UPDATE','DELETE']as$priv){$expected[($kind==='definer'?'_p_':'').$table.':'.$priv]=true;}}
        if($kind!=='definer'){$expected['__vendra_identity:SELECT']=true;}
        $actual=[];
        foreach($this->rows('SELECT TABLE_SCHEMA,TABLE_NAME,PRIVILEGE_TYPE,IS_GRANTABLE FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE=?',[$grantee])as$row){$key=$row['TABLE_NAME'].':'.$row['PRIVILEGE_TYPE'];if($row['TABLE_SCHEMA']!==$target||$row['IS_GRANTABLE']!=='NO'||!isset($expected[$key])){$this->fail('principal_has_unexpected_table_grants');}$actual[$key]=true;}
        if($complete&&count($actual)!==count($expected)){$this->fail('principal_grants_incomplete');}
    }

    private function cloneTable(string $source, string $target, string $table): void
    {
        $base=$this->id($target).'.'.$this->id('_p_'.$table);
        $columns=$this->rows('SELECT COLUMN_NAME,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',[$source,$table]);
        if (in_array(self::SCOPE,array_column($columns,'COLUMN_NAME'),true)) { $this->fail('reserved_scope_column_in_source'); }
        $indexes=$this->rows('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,EXPRESSION,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX',[$source,$table]);
        $this->admin->exec("CREATE TABLE $base LIKE ".$this->id($source).'.'.$this->id($table));
        $auto=array_values(array_filter($columns,fn($c)=>str_contains($c['EXTRA'],'auto_increment')));
        if ($auto) {
            if (count($auto)!==1 || $auto[0]['COLUMN_NAME']!=='id') { $this->fail('unsupported_auto_increment_source'); }
            $this->admin->exec("ALTER TABLE $base ADD INDEX __vendra_native_id (`id`),AUTO_INCREMENT=1");
        }
        if (!$auto && in_array($table,self::SINGLETONS,true)) { $this->admin->exec("ALTER TABLE $base ALTER COLUMN `id` SET DEFAULT 1"); }
        $unique=[];
        foreach ($indexes as $index) {
            if ((int)$index['NON_UNIQUE']!==0) { continue; }
            if ($index['EXPRESSION']!==null || $index['INDEX_TYPE']!=='BTREE' || $index['COLUMN_NAME']===null) { $this->fail('unsupported_unique_index'); }
            $column=$this->id($index['COLUMN_NAME']);if($index['SUB_PART']!==null){$column.='('.(int)$index['SUB_PART'].')';}
            $unique[$index['INDEX_NAME']][]=$column;
        }
        if (! isset($unique['PRIMARY'])) { $this->fail('table_without_primary_key'); }
        $alter=['ADD COLUMN '.self::SCOPE.' BIGINT UNSIGNED NOT NULL DEFAULT 0 FIRST'];
        foreach ($unique as $name=>$columns) { $alter[]=$name==='PRIMARY'?'DROP PRIMARY KEY':'DROP INDEX '.$this->id($name); }
        $alter[]='ADD PRIMARY KEY ('.self::SCOPE.','.implode(',',$unique['PRIMARY']).')';
        foreach ($unique as $name=>$columns) { if($name!=='PRIMARY'){$alter[]='ADD UNIQUE INDEX '.$this->id($name).' ('.self::SCOPE.','.implode(',',$columns).')';} }
        $this->admin->exec("ALTER TABLE $base ".implode(',',$alter));
    }

    /** Full source composite FK metadata; nullable cycles remain enforced and require import backpatch. */
    private function foreignKeys(string $database, bool $physical = false): array
    {
        $rows=$this->rows('SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,k.ORDINAL_POSITION,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA=? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION',[$database]);
        $keys=[];
        foreach($rows as$row){
            if($row['REFERENCED_TABLE_SCHEMA']!==$database){$this->fail('cross_database_foreign_key_rejected');}
            if($physical&&!str_starts_with($row['TABLE_NAME'],'_p_')){continue;}
            $key=$row['TABLE_NAME'].':'.$row['CONSTRAINT_NAME'];
            $keys[$key]??=['table'=>$row['TABLE_NAME'],'name'=>$row['CONSTRAINT_NAME'],'parent'=>$row['REFERENCED_TABLE_NAME'],'columns'=>[],'referenced_columns'=>[],'delete_rule'=>$row['DELETE_RULE'],'update_rule'=>$row['UPDATE_RULE']];
            $keys[$key]['columns'][]=$row['COLUMN_NAME'];$keys[$key]['referenced_columns'][]=$row['REFERENCED_COLUMN_NAME'];
        }
        return array_values($keys);
    }

    private function createForeignKey(string $target, array $fk, array $tables): void
    {
        if(!in_array($fk['table'],$tables,true)||!in_array($fk['parent'],$tables,true)){$this->fail('foreign_key_target_not_allowed');}
        $columns=array_merge([self::SCOPE],$fk['columns']);$parents=array_merge([self::SCOPE],$fk['referenced_columns']);
        $delete=in_array($fk['delete_rule'],['CASCADE','RESTRICT','NO ACTION'],true)?$fk['delete_rule']:'RESTRICT';
        $update=in_array($fk['update_rule'],['CASCADE','RESTRICT','NO ACTION'],true)?$fk['update_rule']:'RESTRICT';
        $name='vf_'.substr(hash('sha256',$fk['table'].':'.$fk['name']),0,40);
        $this->admin->exec('ALTER TABLE '.$this->id($target).'.'.$this->id('_p_'.$fk['table']).' ADD CONSTRAINT '.$this->id($name).' FOREIGN KEY ('.implode(',',array_map($this->id(...),$columns)).') REFERENCES '.$this->id($target).'.'.$this->id('_p_'.$fk['parent']).' ('.implode(',',array_map($this->id(...),$parents)).') ON DELETE '.$delete.' ON UPDATE '.$update);
    }

    private function guardsAndView(string $target, string $table, string $definer): void
    {
        $db=$this->id($target);$base=$db.'.'.$this->id('_p_'.$table);$view=$db.'.'.$this->id($table);$scope=$this->scopeExpression();$login="SUBSTRING_INDEX(USER(),'@',1)";
        $columns=$this->rows('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME<>? ORDER BY ORDINAL_POSITION',[$target,'_p_'.$table,self::SCOPE]);
        $columnNames=array_column($columns,'COLUMN_NAME');$hasId=in_array('id',$columnNames,true);
        $flags="@@SESSION.foreign_key_checks<>1 OR @@SESSION.unique_checks<>1 OR @@SESSION.auto_increment_increment<>1 OR @@SESSION.auto_increment_offset<>1 OR (LEFT($login,4)<>'vxi_' AND FIND_IN_SET('NO_AUTO_VALUE_ON_ZERO',@@SESSION.sql_mode)>0)";
        $check="IF $flags THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='required_session_guards'; END IF;";
        $singleton=in_array($table,self::SINGLETONS,true);
        $insert=$check." SET NEW.".self::SCOPE."=$scope; IF NEW.".self::SCOPE."=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='scope_required'; END IF;";
        if($singleton){$insert.=" IF LEFT($login,4)='vxi_' AND NEW.id<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='singleton_legacy_id_must_be_one'; END IF; SET NEW.id=1;";}
        if($hasId&&!$singleton){$insert.=" IF NEW.id<>0 AND LEFT($login,4)<>'vxi_' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='explicit_runtime_id_rejected'; END IF;";}
        $update=$check.' IF NEW.'.self::SCOPE.'<>OLD.'.self::SCOPE.' OR OLD.'.self::SCOPE."<>$scope".($hasId?' OR NEW.id<>OLD.id':'')." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='scope_or_key_immutable'; END IF;";
        $delete=$check.' IF OLD.'.self::SCOPE."<>$scope THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='scope_required'; END IF;";
        $this->admin->exec("GRANT SELECT,INSERT,UPDATE,DELETE,TRIGGER ON $base TO $definer");
        foreach(['INSERT'=>$insert,'UPDATE'=>$update,'DELETE'=>$delete]as$event=>$body){$trigger='vg_'.strtolower(substr($event,0,1)).'_'.substr(hash('sha256',$table),0,28);$this->admin->exec("CREATE DEFINER=$definer TRIGGER $db.".$this->id($trigger)." BEFORE $event ON $base FOR EACH ROW BEGIN $body END");}
        $projection=implode(',',array_map($this->id(...),$columnNames));
        $this->admin->exec("CREATE ALGORITHM=MERGE DEFINER=$definer SQL SECURITY DEFINER VIEW $view AS SELECT $projection FROM $base WHERE ".self::SCOPE."=$scope AND $scope>0 WITH CASCADED CHECK OPTION");
    }

    private function principal(string $target,int $tenantId,string $username,#[\SensitiveParameter]string $password,string $kind): void
    {
        $this->cli();$this->database($target);$this->username($username);
        if($tenantId<1||$tenantId>PHP_INT_MAX||!preg_match('/^[a-zA-Z0-9]{32,128}$/D',$password)){$this->fail('principal_input_invalid');}
        $prefix=$kind==='runtime'?'vxs_':'vxi_';
        if(!str_starts_with($username,$prefix.sprintf('%016x',$tenantId).'_')){$this->fail('principal_tenant_identity_mismatch');}
        $row=$this->rows('SELECT body FROM '.$this->id($target).'._vendra_schema_manifest WHERE id=1')[0]??null;
        if(!$row){$this->fail('shared_schema_manifest_missing');}
        $manifest=json_decode($row['body'],true,512,JSON_THROW_ON_ERROR);$this->assertExistingSchema($target,$manifest);
        $account=$this->account($username,'127.0.0.1');$created=false;
        try{
            $this->admin->exec('CREATE USER '.$account.' IDENTIFIED BY '.$this->admin->quote($password));$created=true;
            foreach($this->tableNames($manifest)as$table){$this->admin->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON '.$this->id($target).'.'.$this->id($table).' TO '.$account);}
            $this->admin->exec('GRANT SELECT ON '.$this->id($target).'.__vendra_identity TO '.$account);
            $query=$this->admin->prepare('INSERT INTO '.$this->id($target).'._vendra_principals(login,tenant_id,kind)VALUES(?,?,?)');$query->execute([$username,$tenantId,$kind]);
        }catch(\Throwable){if($created){$this->admin->exec('DROP USER IF EXISTS '.$account);}throw new RuntimeException('shared_principal_create_failed');}
    }

    private function guardHash(string $database): string
    {
        $metadata=[];
        $metadata['columns']=$this->rows('SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME,ORDINAL_POSITION',[$database]);
        $metadata['indexes']=$this->rows('SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,EXPRESSION,IS_VISIBLE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX',[$database]);
        $metadata['views']=$this->rows('SELECT TABLE_NAME,VIEW_DEFINITION,CHECK_OPTION,IS_UPDATABLE,DEFINER,SECURITY_TYPE FROM information_schema.VIEWS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME',[$database]);
        $metadata['triggers']=$this->rows('SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_STATEMENT,DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=? ORDER BY TRIGGER_NAME',[$database]);
        $metadata['foreign_keys']=$this->foreignKeys($database,true);
        return $this->hash($metadata);
    }

    private function scopeExpression(): string
    {
        $login="SUBSTRING_INDEX(USER(),'@',1)";
        return "(CASE WHEN REGEXP_LIKE($login,'^vx[si]_[0-9a-f]{16}_[0-9a-f]{8}$','c') THEN CAST(CONV(SUBSTRING($login,5,16),16,10) AS UNSIGNED) ELSE 0 END)";
    }

    private function tableNames(array $manifest): array
    {
        $tables=$manifest['tables']??[];
        if(($manifest['version']??null)!==self::VERSION||!is_array($tables)||!array_is_list($tables)||$tables===[]){$this->fail('manifest_invalid');}
        foreach($tables as$table){if(!is_string($table)||!preg_match('/^[a-z][a-z0-9_]{0,47}$/D',$table)||str_starts_with($table,'_')){$this->fail('table_not_allowed');}}
        return $tables;
    }
    private function username(string $user): void { if(!preg_match('/^vx[si]_[0-9a-f]{16}_[0-9a-f]{8}$/D',$user)){$this->fail('principal_name_invalid');} }
    private function database(string $database): void { if(!preg_match('/^vendra_shared_[a-z0-9_]{1,40}$/D',$database)){$this->fail('shared_database_invalid');} }
    private function id(string $value): string { if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/D',$value)){$this->fail('sql_identifier_invalid');}return '`'.$value.'`'; }
    private function account(string $username,string $host): string { return $this->admin->quote($username).'@'.$this->admin->quote($host); }
    private function rows(string $sql,array $values=[]): array { $query=$this->admin->prepare($sql);$query->execute($values);return $query->fetchAll(PDO::FETCH_ASSOC); }
    private function hash(array $value): string { return self::manifestHash($value); }
    private function cli(): void { if(PHP_SAPI!=='cli'||!function_exists('posix_geteuid')||posix_geteuid()!==0){$this->fail('shared_schema_requires_privileged_cli');} }
    private function fail(string $code): never { throw new RuntimeException($code); }
}
