<?php

namespace App\Tenancy;

use RuntimeException;

final class SharedManifestFile
{
    public const PATH='/var/lib/vendra/controlplane/schema.json';

    public static function load(): array
    {
        self::privileged();
        if (is_link(self::PATH) || !is_file(self::PATH) || realpath(self::PATH)!==self::PATH || fileowner(self::PATH)!==0 || (fileperms(self::PATH)&0077)!==0) {
            throw new RuntimeException('Manifesto privado de estrutura indisponível.');
        }
        try {$manifest=json_decode(file_get_contents(self::PATH),true,flags:JSON_THROW_ON_ERROR);}
        catch (\Throwable) {throw new RuntimeException('Manifesto de estrutura inválido.');}
        if(!is_array($manifest))throw new RuntimeException('Manifesto inválido.');
        $expected=[];
        foreach (glob(database_path('tenant-migrations/*.php')) as $file) $expected[basename($file,'.php')]=hash_file('sha256',$file);
        ksort($expected); $actual=$manifest['migrations']??[]; if(is_array($actual))ksort($actual);
        if (!is_array($manifest) || ($manifest['version']??null)!==1 || ($manifest['guard_policy_sha256']??null)!==SchemaManifest::guardPolicyHash() || $actual!==$expected) {
            throw new RuntimeException('As migrações deste pacote não coincidem com a estrutura compartilhada certificada.');
        }
        return $manifest;
    }

    public static function save(array $manifest): void
    {
        self::privileged();$parent=dirname(self::PATH);
        if (is_link($parent) || realpath($parent)!==$parent || fileowner($parent)!==0 || (fileperms($parent)&0077)!==0 || is_link(self::PATH)) {
            throw new RuntimeException('Diretório privado do manifesto inválido.');
        }
        $temporary=tempnam($parent,'.schema-');
        try {
            chmod($temporary,0600);
            if (file_put_contents($temporary,json_encode($manifest,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT))===false || !rename($temporary,self::PATH)) {
                throw new RuntimeException('Não foi possível salvar o manifesto.');
            }
        } finally {if (is_file($temporary))unlink($temporary);}
    }
    private static function privileged(): void
    {
        if(PHP_SAPI!=='cli'||!function_exists('posix_geteuid')||posix_geteuid()!==0)throw new RuntimeException('Acesso administrativo somente pelo console root.');
    }
}
