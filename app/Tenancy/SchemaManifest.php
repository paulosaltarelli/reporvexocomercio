<?php

namespace App\Tenancy;

use PDO;
use RuntimeException;

/** Read-only description of the exact tenant schema certified for this release. */
final class SchemaManifest
{
    public static function capture(PDO $admin, string $template): array
    {
        self::identifier($template);
        if (!preg_match('/^vendra_t_[a-z0-9_]+$/D',$template)) throw new RuntimeException('Fonte de estrutura inválida.');
        $migrations=[]; $tables=['migrations'];
        foreach (glob(database_path('tenant-migrations/*.php')) as $file) {
            if (is_link($file)) throw new RuntimeException('Migração simbólica não permitida.');
            $migrations[basename($file,'.php')]=hash_file('sha256',$file);
            preg_match_all('/Schema::(?:connection\([^)]*\)\s*->\s*)?create\(\s*[\'"]([a-z][a-z0-9_]*)[\'"]/m',file_get_contents($file),$matches);
            foreach ($matches[1] as $table) $tables[]=$table;
        }
        ksort($migrations); $tables=array_values(array_unique($tables)); sort($tables);
        $query=$admin->prepare('SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME');
        $query->execute([$template]); $actual=[];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['TABLE_TYPE']!=='BASE TABLE') throw new RuntimeException('A fonte deve conter somente tabelas operacionais.');
            $actual[]=$row['TABLE_NAME'];
        }
        if ($actual!==$tables) throw new RuntimeException('Estrutura contém tabelas ausentes ou não revisadas.');
        $installed=$admin->query('SELECT migration FROM `'.$template.'`.`migrations` ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
        if ($installed!==array_keys($migrations)) throw new RuntimeException('A fonte não contém exatamente as migrações tenant deste release.');
        $trigger=$admin->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=?');
        $trigger->execute([$template]);
        if ((int)$trigger->fetchColumn()!==0) throw new RuntimeException('Triggers não revisados na origem.');
        $schema=[];
        foreach ($tables as $table) {
            self::identifier($table);
            $row=$admin->query('SHOW CREATE TABLE `'.$template.'`.`'.$table.'`')->fetch(PDO::FETCH_NUM);
            $ddl=preg_replace('/ AUTO_INCREMENT=\d+\b/','',$row[1]);
            $schema[$table]=str_replace('`'.$template.'`.','',$ddl);
        }
        return ['version'=>1,'guard_policy_sha256'=>self::guardPolicyHash(),'migration_sha256'=>hash('sha256',self::json($migrations)),'migrations'=>$migrations,'tables'=>$tables,'source_fingerprint'=>hash('sha256',self::json($schema))];
    }

    public static function assertTemplate(PDO $admin,string $template,array $manifest): void
    {
        if (self::capture($admin,$template)!==$manifest) throw new RuntimeException('A estrutura diverge do manifesto certificado.');
    }

    public static function guardPolicyHash(): string
    {
        $policy=[];
        foreach(['build','assertExistingSchema','cloneTable','createForeignKey','guardsAndView','scopeExpression','principal','tableNames','username','guardHash'] as $name) {
            $method=new \ReflectionMethod(SharedSchemaBuilder::class,$name);
            $lines=file($method->getFileName());
            $policy[$name]=implode('',array_slice($lines,$method->getStartLine()-1,$method->getEndLine()-$method->getStartLine()+1));
        }
        return hash('sha256',self::json($policy));
    }

    private static function identifier(string $value): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$value)) throw new RuntimeException('Identificador SQL inválido.');
    }

    private static function json(array $value): string
    {
        return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }
}
