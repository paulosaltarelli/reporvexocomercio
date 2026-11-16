<?php

namespace App\Tenancy;

use PDO;
use RuntimeException;

/** Privileged CLI boundary. The web process cannot open administrative credentials. */
final class ControlPlane
{
    public static function pdo(): PDO
    {
        if (PHP_SAPI!=='cli' || !function_exists('posix_geteuid') || posix_geteuid()!==0) {
            throw new RuntimeException('Control plane disponível somente no console administrativo.');
        }
        $file=(string)config('tenancy.controlplane_file');
        if ($file!=='/var/lib/vendra/controlplane/shared.json' || is_link($file) || !is_file($file)
            || realpath($file)!==$file || fileowner($file)!==0 || (fileperms($file)&0077)!==0) {
            throw new RuntimeException('Configuração administrativa privada indisponível.');
        }
        foreach (['/var/lib/vendra/controlplane','/var/lib/vendra'] as $parent) {
            if (is_link($parent) || realpath($parent)!==$parent || fileowner($parent)!==0 || (fileperms($parent)&0022)!==0) {
                throw new RuntimeException('Diretório administrativo inválido.');
            }
        }
        try {
            $values=json_decode(file_get_contents($file),true,flags:JSON_THROW_ON_ERROR);
            if (!is_array($values) || ($values['database']??null)!==config('tenancy.shared.database')
                || ($values['socket']??null)!=='/var/run/mysqld/mysqld.sock'
                || !is_string($values['username']??null) || !preg_match('/^[a-zA-Z0-9_]{1,32}$/D',$values['username'])
                || !is_string($values['password']??null)) {
                throw new RuntimeException('Invalid control-plane settings.');
            }
            return new PDO('mysql:unix_socket='.$values['socket'].';charset=utf8mb4',$values['username'],$values['password'],[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
            ]);
        } catch (\Throwable) {
            // Do not propagate PDO/JSON messages or credential-bearing exception traces.
            throw new RuntimeException('Não foi possível abrir o control plane administrativo.');
        }
    }
}
