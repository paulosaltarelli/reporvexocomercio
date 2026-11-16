<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Platform\PlatformAudit;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

/** CLI-only copy/cutover. Source databases are never modified or removed. */
final class SharedTenantImporter
{
    private ?PDO $writer = null;
    private ?string $importUser = null;
    private ?string $runtimeUser = null;
    private bool $cutoverKnown = false;
    private bool $cutoverUncertain = false;

    public function __construct(private readonly PDO $admin, private readonly SharedSchemaBuilder $builder) {}

    public function run(Tenant $tenant, array $manifest, bool $apply = false, ?string $journal = null): array
    {
        $this->guardTenant($tenant);
        $source = (string) $tenant->db_name;
        $target = (string) config('tenancy.shared.database');
        $this->identifier($target);
        if ($source === $target) throw new RuntimeException('Fonte e destino precisam ser distintos.');
        $this->builder->assertExistingSchema($target, $manifest);
        SchemaManifest::assertTemplate($this->admin, $source, $manifest);
        $schemaHash = SharedSchemaBuilder::manifestHash($manifest);
        $metadata = $this->metadata($source);
        $names = array_keys($metadata); $expectedNames = $manifest['tables'] ?? [];
        sort($names); sort($expectedNames);
        if ($names !== $expectedNames) throw new RuntimeException('Manifesto não cobre exatamente todas as tabelas da fonte.');
        $before = $this->snapshot($this->admin, $source, $metadata);
        $this->builder->assertEmptyNamespace($target, $tenant->id, $manifest);
        $this->assertEmpty($target, $tenant->id, $metadata);
        $summary = ['tenant_id' => (int) $tenant->id, 'mode' => $apply ? 'apply' : 'dry-run', 'tables' => $before];
        if (! $apply) return $summary;
        $lock = $this->lock();
        $requestedJournal = $journal; $journal = null;
        try {
            $this->assertQuiesced($tenant, $target);
            $this->assertFiscalDrained($source);
            $journal = $this->guardJournal($requestedJournal, $tenant, $source);
            $this->record($journal, 'before.json', $summary);
            // Raw encrypted central fields support a reviewed connection rollback,
            // only after proving the shared namespace has received no later writes.
            $this->record($journal, 'tenant-before.json', $tenant->getRawOriginal());
            $this->builder->assertEmptyNamespace($target, $tenant->id, $manifest);
            $this->assertEmpty($target, $tenant->id, $metadata);
            // No session may turn off FK or unique checks, including temporary imports.
            $this->assertChecks($this->admin);
            $this->importUser = sprintf('vxi_%016x_%s', $tenant->id, bin2hex(random_bytes(4)));
            $this->runtimeUser = sprintf('vxs_%016x_%s', $tenant->id, bin2hex(random_bytes(4)));
            $this->record($journal, 'principals.json', ['import' => $this->importUser, 'runtime' => $this->runtimeUser]);
            $password = bin2hex(random_bytes(32));
            $this->builder->createImport($target, $tenant->id, $this->importUser, $password);
            $this->writer = $this->principal($target, $this->importUser, $password);
            unset($password);
            $this->assertServer($this->writer);
            $this->assertIdentity($this->writer, (int) $tenant->id, $schemaHash);
            $this->writer->exec("SET SESSION sql_mode=CONCAT_WS(',',NULLIF(@@SESSION.sql_mode,''),'NO_AUTO_VALUE_ON_ZERO')");
            $this->assertChecks($this->writer);
            $this->writer->beginTransaction();
            $order = $this->order($metadata);
            foreach ($order as $table) $this->insertTable($source, $table, $metadata[$table]);
            foreach ($order as $table) $this->backpatch($source, $table, $metadata[$table]);
            $after = $this->snapshot($this->writer, $target, $metadata);
            if ($before !== $after) throw new RuntimeException('Reconciliação integral da cópia falhou.');
            $this->writer->commit();
            $this->builder->assertTenantIntegrity($target, $tenant->id, $manifest);
            $this->assertForeignKeys($target, (int) $tenant->id, $metadata);
            // Detect any uncooperative source writer before even preparing a cutover.
            if ($before !== $this->snapshot($this->admin, $source, $metadata)) throw new RuntimeException('Fonte mudou durante a cópia; cutover proibido.');
            $runtimePassword = bin2hex(random_bytes(32));
            $this->builder->registerRuntime($target, $tenant->id, $this->runtimeUser, $runtimePassword);
            $runtime = $this->principal($target, $this->runtimeUser, $runtimePassword);
            $this->assertServer($runtime);
            $this->assertIdentity($runtime, (int) $tenant->id, $schemaHash);
            if ($before !== $this->snapshot($runtime, $target, $metadata)) throw new RuntimeException('Reconciliação pela identidade runtime falhou.');
            $this->assertQuiesced($tenant, $target);
            SchemaManifest::assertTemplate($this->admin, $source, $manifest);
            $this->builder->assertExistingSchema($target, $manifest);
            $this->assertFiscalDrained($source);
            $token = bin2hex(random_bytes(16));
            $this->record($journal, 'copy-verified.json', ['tenant_id' => $tenant->id, 'tables' => $after, 'cutover_token' => $token]);
            $this->cutover($tenant, $source, $target, $runtimePassword, $token, $journal);
            unset($runtimePassword);
            $this->record($journal, 'status.json', ['status' => 'cutover-confirmed', 'tenant_id' => $tenant->id]);
            return $summary + ['status' => 'cutover-confirmed'];
        } catch (Throwable) {
            try { if ($this->writer?->inTransaction()) $this->writer->rollBack(); } catch (Throwable) { /* Quarantine remains mandatory. */ }
            if ($journal && is_dir($journal) && !file_exists($journal.'/status.json')) $this->record($journal, 'status.json', ['status' => $this->cutoverUncertain ? 'cutover-unknown-reconcile-required' : ($this->cutoverKnown ? 'cutover-confirmed-journal-review-required' : 'namespace-quarantined'), 'tenant_id' => $tenant->id]);
            // Never delete source/target rows and never guess whether a commit happened.
            throw new RuntimeException('Importação interrompida; mantenha Vendra e timer pausados e consulte o journal privado.');
        } finally {
            $this->writer = null;
            $cleanupFailed = false;
            try {
                if ($this->importUser) $this->revoke($target, $this->importUser);
                if ($this->runtimeUser && ! $this->cutoverKnown && ! $this->cutoverUncertain) $this->revoke($target, $this->runtimeUser);
            } catch (Throwable) {
                $cleanupFailed = true;
            }
            flock($lock, LOCK_UN);
            fclose($lock);
            if ($cleanupFailed) throw new RuntimeException('Revogação administrativa pendente; mantenha aplicação pausada e reconcilie contas pelo journal privado.');
        }
    }

    private function revoke(string $target, string $user): void
    {
        $q = $this->admin->prepare('SELECT COUNT(*) FROM '.$this->identifier($target).'._vendra_principals WHERE login=?');
        $q->execute([$user]);
        if ((int) $q->fetchColumn() === 1) { $this->builder->dropPrincipal($target, $user); return; }
        $q = $this->admin->prepare("SELECT COUNT(*) FROM mysql.user WHERE User=? AND Host='127.0.0.1'");
        $q->execute([$user]);
        if ((int) $q->fetchColumn() !== 0) throw new RuntimeException('Conta não registrada requer reconciliação administrativa.');
    }

    private function guardTenant(Tenant $tenant): void
    {
        if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) throw new RuntimeException('Importador restrito ao console root.');
        if ($tenant->storage_mode !== 'isolated' || ! preg_match('/^vendra_t_[a-z0-9_]+$/D', (string) $tenant->db_name)
            || ! in_array($tenant->db_host, ['127.0.0.1', 'localhost'], true) || ! $tenant->exists) throw new RuntimeException('Fonte isolada local inválida.');
        if (! config('tenancy.shared.enabled') || config('tenancy.shared.host') !== '127.0.0.1' || (int) config('tenancy.shared.port') !== 3306) throw new RuntimeException('Destino compartilhado local não habilitado.');
    }

    private function identifier(string $name): string
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/D', $name)) throw new RuntimeException('Identificador inválido.');
        return '`'.$name.'`';
    }

    private function metadata(string $source): array
    {
        $q = $this->admin->prepare("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
        $q->execute([$source]); $tables = $q->fetchAll(PDO::FETCH_ASSOC); $out = [];
        foreach ($tables as $row) {
            $name = $row['TABLE_NAME']; $this->identifier($name);
            if ($row['ENGINE'] !== 'InnoDB') throw new RuntimeException('Fonte exige tabelas transacionais.');
            $q = $this->admin->prepare('SELECT COLUMN_NAME,IS_NULLABLE,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
            $q->execute([$source, $name]); $columns = $q->fetchAll(PDO::FETCH_ASSOC);
            foreach ($columns as $c) if (str_contains($c['EXTRA'], 'GENERATED')) throw new RuntimeException('Coluna gerada exige revisão específica.');
            $q = $this->admin->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION");
            $q->execute([$source, $name]); $pk = $q->fetchAll(PDO::FETCH_COLUMN);
            if (! $pk) throw new RuntimeException('Tabela sem PK não pode ser reconciliada.');
            $q = $this->admin->prepare('SELECT CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_SCHEMA,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME,ORDINAL_POSITION');
            $q->execute([$source, $name]); $fks = []; $deferred = []; $nullable = array_column($columns, 'IS_NULLABLE', 'COLUMN_NAME');
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $fk) {
                if ($fk['REFERENCED_TABLE_SCHEMA'] !== $source) throw new RuntimeException('FK externa à fonte não suportada.');
                $fks[$fk['CONSTRAINT_NAME']][] = $fk;
                if ($nullable[$fk['COLUMN_NAME']] === 'YES') $deferred[$fk['COLUMN_NAME']] = true;
            }
            if (array_intersect($pk, array_keys($deferred))) throw new RuntimeException('PK nullable em ciclo não suportada.');
            if (in_array($name, SharedSchemaBuilder::SINGLETONS, true)) {
                $illegal = $this->admin->query('SELECT COUNT(*) FROM '.$this->identifier($source).'.'.$this->identifier($name).' WHERE id<>1')->fetchColumn();
                if ((int) $illegal !== 0) throw new RuntimeException('Singleton de origem inválido; revisão manual sem reescrever IDs.');
            }
            $out[$name] = ['columns' => array_column($columns, 'COLUMN_NAME'), 'pk' => $pk, 'fks' => $fks, 'deferred' => array_keys($deferred)];
        }
        if (! $out) throw new RuntimeException('Fonte vazia ou inválida.');
        $this->order($out); // Fail required cycles even in dry-run, before any CREATE USER.
        return $out;
    }

    private function order(array $metadata): array
    {
        $pending = [];
        foreach ($metadata as $name => $m) {
            $pending[$name] = [];
            foreach ($m['fks'] as $fk) {
                // Any nullable component disables this composite FK during pass one.
                if (array_intersect(array_column($fk, 'COLUMN_NAME'), $m['deferred'])) continue;
                $parent = $fk[0]['REFERENCED_TABLE_NAME'];
                if (! isset($metadata[$parent])) throw new RuntimeException('FK fora do manifesto da fonte.');
                $pending[$name][$parent] = true;
            }
        }
        $result = [];
        while ($pending) {
            $ready = array_keys(array_filter($pending, fn ($deps) => ! $deps));
            if (! $ready) throw new RuntimeException('Ciclo FK obrigatório/self-reference requer revisão; checks nunca são desativados.');
            sort($ready);
            foreach ($ready as $name) { $result[] = $name; unset($pending[$name]); foreach ($pending as &$deps) unset($deps[$name]); unset($deps); }
        }
        return $result;
    }

    private function selectRows(PDO $pdo, string $database, string $table, array $m): \PDOStatement
    {
        $columns = implode(',', array_map($this->identifier(...), $m['columns']));
        $order = implode(',', array_map($this->identifier(...), $m['pk']));
        return $pdo->query('SELECT '.$columns.' FROM '.$this->identifier($database).'.'.$this->identifier($table).' ORDER BY '.$order);
    }

    private function snapshot(PDO $pdo, string $database, array $metadata): array
    {
        $out = [];
        foreach ($metadata as $table => $m) {
            $ctx = hash_init('sha256'); $count = 0;
            hash_update($ctx, json_encode($m['columns'], JSON_THROW_ON_ERROR));
            $q = $this->selectRows($pdo, $database, $table, $m);
            while (($row = $q->fetch(PDO::FETCH_NUM)) !== false) {
                hash_update($ctx, 'R');
                foreach ($row as $value) {
                    if ($value === null) hash_update($ctx, 'N;');
                    else { $value = (string) $value; hash_update($ctx, 'V'.strlen($value).':'.$value.';'); }
                }
                ++$count;
            }
            hash_update($ctx, 'COUNT:'.$count);
            $out[$table] = ['count' => $count, 'sha256' => hash_final($ctx)];
        }
        return $out;
    }

    private function insertTable(string $source, string $table, array $m): void
    {
        $cols = implode(',', array_map($this->identifier(...), $m['columns']));
        $insert = $this->writer->prepare('INSERT INTO '.$this->identifier($table).' ('.$cols.') VALUES ('.implode(',', array_fill(0, count($m['columns']), '?')).')');
        $q = $this->selectRows($this->admin, $source, $table, $m);
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            foreach ($m['deferred'] as $column) $row[$column] = null;
            $insert->execute(array_values($row));
        }
    }

    private function backpatch(string $source, string $table, array $m): void
    {
        if (! $m['deferred']) return;
        $set = implode(',', array_map(fn ($c) => $this->identifier($c).'=?', $m['deferred']));
        $where = implode(' AND ', array_map(fn ($c) => $this->identifier($c).'=?', $m['pk']));
        $update = $this->writer->prepare('UPDATE '.$this->identifier($table).' SET '.$set.' WHERE '.$where);
        $q = $this->selectRows($this->admin, $source, $table, $m);
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) $update->execute(array_map(fn ($c) => $row[$c], [...$m['deferred'], ...$m['pk']]));
    }

    private function assertEmpty(string $target, int $tenantId, array $metadata): void
    {
        $registered = $this->admin->prepare('SELECT COUNT(*) FROM '.$this->identifier($target).'._vendra_principals WHERE tenant_id=?');
        $registered->execute([$tenantId]);
        if ((int) $registered->fetchColumn() !== 0) throw new RuntimeException('Namespace já possui principal; requer revisão manual.');
        foreach ($metadata as $table => $_) {
            $q = $this->admin->prepare('SELECT 1 FROM '.$this->identifier($target).'.'.$this->identifier('_p_'.$table).' WHERE _scope_tenant_id=? LIMIT 1');
            $q->execute([$tenantId]);
            if ($q->fetchColumn() !== false) throw new RuntimeException('Namespace de destino não vazio; requer revisão de quarentena.');
        }
    }

    private function assertForeignKeys(string $target, int $tenantId, array $metadata): void
    {
        foreach ($metadata as $table => $m) foreach ($m['fks'] as $fk) {
            $parent = $fk[0]['REFERENCED_TABLE_NAME'];
            $join = ['p._scope_tenant_id=c._scope_tenant_id']; $notnull = [];
            foreach ($fk as $part) {
                $join[] = 'p.'.$this->identifier($part['REFERENCED_COLUMN_NAME']).'=c.'.$this->identifier($part['COLUMN_NAME']);
                $notnull[] = 'c.'.$this->identifier($part['COLUMN_NAME']).' IS NOT NULL';
            }
            $sql = 'SELECT 1 FROM '.$this->identifier($target).'.'.$this->identifier('_p_'.$table).' c LEFT JOIN '.$this->identifier($target).'.'.$this->identifier('_p_'.$parent).' p ON '.implode(' AND ', $join).' WHERE c._scope_tenant_id=? AND '.implode(' AND ', $notnull).' AND p._scope_tenant_id IS NULL LIMIT 1';
            $q = $this->admin->prepare($sql); $q->execute([$tenantId]);
            if ($q->fetchColumn() !== false) throw new RuntimeException('Referência FK inválida no namespace importado.');
        }
    }

    private function principal(string $database, string $user, #[\SensitiveParameter] string $password): PDO
    {
        return new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$database.';charset=utf8mb4', $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => true]);
    }

    private function assertServer(PDO $pdo): void
    {
        $expected = $this->admin->query('SELECT @@server_uuid AS id,@@SESSION.time_zone AS tz')->fetch(PDO::FETCH_ASSOC);
        $actual = $pdo->query('SELECT @@server_uuid AS id,@@SESSION.time_zone AS tz')->fetch(PDO::FETCH_ASSOC);
        if ($expected !== $actual) throw new RuntimeException('Instância MySQL ou fuso da sessão divergente.');
    }

    private function assertIdentity(PDO $pdo, int $id, string $schemaHash): void
    {
        $rows = $pdo->query('SELECT tenant_id,schema_hash FROM __vendra_identity')->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1 || (int) $rows[0]['tenant_id'] !== $id || ! hash_equals($schemaHash, (string) $rows[0]['schema_hash'])) throw new RuntimeException('Identidade SQL não corresponde ao tenant.');
    }

    private function assertChecks(PDO $pdo): void
    {
        $r = $pdo->query('SELECT @@SESSION.foreign_key_checks AS f,@@SESSION.unique_checks AS u')->fetch(PDO::FETCH_ASSOC);
        if ((int) $r['f'] !== 1 || (int) $r['u'] !== 1) throw new RuntimeException('FK/unique checks precisam permanecer ativos.');
    }

    private function lock()
    {
        $path = '/run/lock/vendra-fiscal-deploy.lock';
        if (is_link($path)) throw new RuntimeException('Lock inválido.');
        $f = fopen($path, 'c');
        if (! $f || ! flock($f, LOCK_EX | LOCK_NB)) throw new RuntimeException('Outro processo controla a implantação.');
        return $f;
    }

    private function assertFiscalDrained(string $source): void
    {
        $pending = $this->admin->query('SELECT COUNT(*) FROM '.$this->identifier($source).'.fiscal_outbox WHERE completed_at IS NULL')->fetchColumn();
        if ((int) $pending !== 0) throw new RuntimeException('Fila fiscal pendente deve estar vazia antes do cutover.');
    }

    private function allowsQaApply(Tenant $tenant, string $target): bool
    {
        if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0
            || ! app()->environment('testing') || config('database.connections.central.driver') !== 'mysql'
            || config('database.connections.central.username') !== 'vendra_test'
            || ! in_array($tenant->db_name, ['vendra_t_test_a', 'vendra_t_test_b'], true)
            || ! preg_match('/^vendra_shared_qa_[a-f0-9]{8}$/D', $target)) return false;
        $pdo = DB::connection('central')->getPdo();
        $row = $pdo->query("SELECT DATABASE() AS db,SUBSTRING_INDEX(CURRENT_USER(),'@',1) AS login")->fetch(PDO::FETCH_ASSOC);
        return $row['db'] === 'vendra_test_central' && $row['login'] === 'vendra_test';
    }

    private function assertQuiesced(Tenant $tenant, string $target): void
    {
        // This narrowly bounded SQL-only QA seam cannot match the live demonstration.
        // All other locks, private journal/backup, schema, identity and cutover checks remain.
        if ($this->allowsQaApply($tenant, $target)) return;
        foreach (['vendra.service', 'vendra-fiscal.service', 'vendra-fiscal.timer'] as $unit) {
            $out = []; $status = 0;
            exec('/usr/bin/systemctl show --property=ActiveState --value '.escapeshellarg($unit).' 2>/dev/null', $out, $status);
            if ($status !== 0 || trim(implode('', $out)) !== 'inactive') throw new RuntimeException('Vendra, timer e worker devem estar pausados e drenados antes de aplicar.');
        }
    }

    private function guardJournal(?string $path, Tenant $tenant, string $source): string
    {
        if (! $path || ! preg_match('#^/var/lib/vendra/shared-import/[a-zA-Z0-9._-]+$#D', $path)) throw new RuntimeException('Journal privado obrigatório.');
        foreach ([$path, dirname($path)] as $p) {
            if (is_link($p) || ! is_dir($p) || realpath($p) !== $p || fileowner($p) !== 0 || (fileperms($p) & 0077) !== 0) throw new RuntimeException('Journal precisa pertencer ao root, sem acesso de grupo/outros.');
        }
        if (is_link('/var/lib/vendra') || realpath('/var/lib/vendra') !== '/var/lib/vendra' || fileowner('/var/lib/vendra') !== 0 || (fileperms('/var/lib/vendra') & 0022) !== 0) throw new RuntimeException('Ancestral do journal inválido.');
        $file = $path.'/backup.json';
        if (is_link($file) || ! is_file($file) || fileowner($file) !== 0 || (fileperms($file) & 0077) !== 0) throw new RuntimeException('ACK privado de backup obrigatório.');
        $ack = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if (($ack['status'] ?? '') !== 'quiesced-verified' || ($ack['tenant_id'] ?? null) !== (int) $tenant->id || ($ack['source_database'] ?? '') !== $source || abs(time() - (int) ($ack['verified_at'] ?? 0)) > 3600) throw new RuntimeException('ACK de backup expirado ou incompatível.');
        $backup = $ack['backup_path'] ?? '';
        if (! is_string($backup) || ! preg_match('#^/var/backups/vendra/[0-9TZ]+-[A-Za-z0-9]+$#D', $backup) || is_link($backup) || realpath($backup) !== $backup) throw new RuntimeException('Backup inválido.');
        $sha = $backup.'/SHA256SUMS';
        if (is_link($sha) || ! is_file($sha)) throw new RuntimeException('Manifesto de backup ausente.');
        $seen = [];
        foreach (file($sha, FILE_IGNORE_NEW_LINES) as $line) {
            if (! preg_match('/^([a-f0-9]{64})  (databases\.txt|databases\.sql|application\.tar\.gz|controlplane\.tar\.gz)$/D', $line, $m) || isset($seen[$m[2]])) throw new RuntimeException('Manifesto de backup inválido.');
            $p = $backup.'/'.$m[2];
            if (is_link($p) || ! is_file($p) || filesize($p) === 0 || hash_file('sha256', $p) !== $m[1]) throw new RuntimeException('Integridade de backup inválida.');
            $seen[$m[2]] = true;
        }
        if (array_diff(['databases.txt', 'databases.sql', 'application.tar.gz'], array_keys($seen)) || count($seen) > 4) throw new RuntimeException('Backup incompleto.');
        $databases = file($backup.'/databases.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $central = DB::connection('central')->getDatabaseName();
        if (! in_array($source, $databases, true) || ! in_array($central, $databases, true)) throw new RuntimeException('Backup não contém os bancos de origem/central.');
        if ($this->allowsQaApply($tenant, (string) config('tenancy.shared.database'))) {
            foreach ($databases as $name) if (! in_array($name, ['vendra_test_central', 'vendra_t_test_a', 'vendra_t_test_b', config('tenancy.shared.database')], true)) throw new RuntimeException('Backup QA contém banco fora do teste.');
        }
        exec('/usr/bin/tar -tzf '.escapeshellarg($backup.'/application.tar.gz').' >/dev/null 2>&1', $ignored, $status);
        if ($status !== 0) throw new RuntimeException('Tar de backup inválido.');
        if (isset($seen['controlplane.tar.gz'])) {
            exec('/usr/bin/tar -tzf '.escapeshellarg($backup.'/controlplane.tar.gz').' >/dev/null 2>&1', $ignored, $status);
            if ($status !== 0) throw new RuntimeException('Tar administrativo de backup inválido.');
        }
        return $path;
    }

    private function record(string $journal, string $name, array $value): void
    {
        $p = $journal.'/'.$name;
        if (file_exists($p) || is_link($p)) throw new RuntimeException('Journal já contém esta fase; revisão manual obrigatória.');
        $f = fopen($p, 'x');
        if (! $f) throw new RuntimeException('Não foi possível gravar journal.');
        chmod($p, 0600);
        fwrite($f, json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)); fflush($f); if (function_exists('fsync')) fsync($f); fclose($f);
    }

    private function cutover(Tenant $tenant, string $source, string $target, #[\SensitiveParameter] string $password, string $token, string $journal): void
    {
        $oldVersion = (int) $tenant->platform_version;
        try {
            $this->cutoverUncertain = true;
            DB::connection('central')->transaction(function () use ($tenant, $source, $target, $password, $token, $oldVersion) {
                $current = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                if ($current->storage_mode !== 'isolated' || $current->db_name !== $source || (int) $current->platform_version !== $oldVersion || $current->active !== $tenant->active || $current->db_host !== $tenant->db_host || $current->db_username !== $tenant->db_username || $current->getRawOriginal('db_password') !== $tenant->getRawOriginal('db_password')) throw new RuntimeException('Tenant central mudou; cutover cancelado.');
                $current->forceFill(['db_host' => config('tenancy.shared.host'), 'db_name' => $target, 'db_username' => $this->runtimeUser, 'db_password' => $password, 'storage_mode' => 'shared', 'platform_version' => $oldVersion + 1, 'provisioned_at' => now()])->save();
                PlatformAudit::create(['tenant_id' => $tenant->id, 'platform_admin_id' => null, 'action' => 'tenant.shared_import', 'actor_name' => 'Vexo/importação CLI', 'changes' => ['token' => $token, 'before_storage_mode' => 'isolated', 'after_storage_mode' => 'shared', 'before_version' => $oldVersion, 'after_version' => $oldVersion + 1]]);
            });
            $this->cutoverKnown = true; $this->cutoverUncertain = false;
        } catch (Throwable) {
            // A lost COMMIT response is not evidence of rollback. Reconnect and inspect.
            try {
                DB::purge('central');
                $current = Tenant::findOrFail($tenant->id);
                $audit = PlatformAudit::where('tenant_id', $tenant->id)->where('action', 'tenant.shared_import')->where('changes->token', $token)->exists();
                if ($current->storage_mode === 'shared' && $current->db_name === $target && $current->db_username === $this->runtimeUser && (int) $current->platform_version === $oldVersion + 1 && $audit) {
                    $this->cutoverKnown = true; $this->cutoverUncertain = false;
                    $this->record($journal, 'commit-reconciled.json', ['tenant_id' => $tenant->id, 'committed' => true]);
                    return;
                }
                if ($current->storage_mode === 'isolated' && $current->db_name === $source && (int) $current->platform_version === $oldVersion && ! $audit) $this->cutoverUncertain = false;
            } catch (Throwable) { /* Keep unknown; do not revoke a potentially live account. */ }
            throw new RuntimeException('Cutover central requer reconciliação privada.');
        }
    }
}
