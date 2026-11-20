<?php

namespace App\Platform;

use Closure;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

final class SmtpSettings
{
    private function path(): string
    {
        return config('platform.smtp_settings_path');
    }

    private function saved(): ?array
    {
        $path=$this->path();
        clearstatcache(true,$path);
        if (is_link($path)) throw new \RuntimeException('smtp_storage_invalid');
        if (!file_exists($path)) return null;
        if (!is_file($path) || (fileperms($path) & 0077)) throw new \RuntimeException('smtp_storage_permissions');
        $encrypted=file_get_contents($path);
        if ($encrypted===false) throw new \RuntimeException('smtp_storage_read');
        return json_decode(Crypt::decryptString($encrypted),true,flags:JSON_THROW_ON_ERROR);
    }

    private function fallback(): array
    {
        $config=config('mail.mailers.smtp');
        if (!empty($config['url'])) {
            $parsed=(new ConfigurationUrlParser)->parseConfiguration($config);
            $config=array_merge($config,$parsed,['scheme'=>$parsed['driver']??$config['scheme']]);
        }
        return ['host'=>strtolower((string)$config['host']), 'port'=>(int)$config['port'],
            'security'=>($config['scheme']??null)==='smtps'||(int)$config['port']===465?'tls':'starttls',
            'username'=>(string)($config['username']??''), 'password'=>(string)($config['password']??''),
            'from_address'=>(string)config('mail.from.address'), 'from_name'=>(string)config('mail.from.name')];
    }

    private function version(#[\SensitiveParameter] array $values): string
    {
        return hash_hmac('sha256',json_encode($values,JSON_THROW_ON_ERROR),config('app.key'));
    }

    public function display(): array
    {
        $saved=$this->saved();
        $settings=$saved??$this->fallback();
        $view=$settings;
        unset($view['password']);
        return $view+['hasPassword'=>$settings['password']!=='','version'=>$this->version($settings),'saved'=>$saved!==null];
    }

    public function candidate(#[\SensitiveParameter] array $input): array
    {
        $current=$this->saved()??$this->fallback();
        $this->assertVersion($input['version'],$current);
        $input['host']=strtolower($input['host']);
        $input['port']=(int)$input['port'];
        if (!isset($input['password']) || $input['password']==='') {
            foreach (['host','port','security','username'] as $key) {
                if ($input[$key]!==$current[$key]) throw ValidationException::withMessages(['password'=>'Informe a senha ao mudar o servidor, a porta, o TLS ou o usuário.']);
            }
            $input['password']=$current['password'];
        }
        if ($input['password']==='') throw ValidationException::withMessages(['password'=>'Informe a senha da conta de envio.']);
        unset($input['version']);
        return $input;
    }

    private function assertVersion(string $expected, #[\SensitiveParameter] array $current): void
    {
        if (!hash_equals($this->version($current),$expected)) {
            throw ValidationException::withMessages(['version'=>'As configurações mudaram. Recarregue a página antes de continuar.']);
        }
    }

    public function save(#[\SensitiveParameter] array $settings,string $expected): void
    {
        $path=$this->path();
        $dir=dirname($path);
        if (!is_dir($dir) || is_link($dir) || (fileperms($dir) & 0077)) throw new \RuntimeException('smtp_storage_directory');
        $lockPath=$dir.'/settings.lock';
        if (is_link($lockPath)) throw new \RuntimeException('smtp_storage_lock');
        $previousMask=umask(0077);
        try {
            $lock=fopen($lockPath,'c');
        } finally {
            umask($previousMask);
        }
        if (!$lock) throw new \RuntimeException('smtp_storage_lock');
        try {
            if (!flock($lock,LOCK_EX)) throw new \RuntimeException('smtp_storage_lock');
            $saved=$this->saved();
            $this->assertVersion($expected,$saved??$this->fallback());
            if ($saved!==null) $this->writeEncrypted($path.'.previous',$saved);
            $this->writeEncrypted($path,$settings);
        } finally {
            fclose($lock);
        }
    }

    private function writeEncrypted(string $target,#[\SensitiveParameter] array $values): void
    {
        $cipher=Crypt::encryptString(json_encode($values,JSON_THROW_ON_ERROR));
        $temporary=tempnam(dirname($target),'smtp-');
        if (!$temporary) throw new \RuntimeException('smtp_storage_temp');
        try {
            if (dirname($temporary)!==dirname($target) || !chmod($temporary,0600)
                || file_put_contents($temporary,$cipher)!==strlen($cipher) || !rename($temporary,$target)) {
                throw new \RuntimeException('smtp_storage_write');
            }
        } finally {
            if (file_exists($temporary)) unlink($temporary);
        }
    }

    public function testCurrent(SmtpConnectionTest $probe): void
    {
        $current = $this->saved() ?? $this->fallback();
        if ($current['username'] === '' || $current['password'] === '') {
            throw new \RuntimeException('smtp_credentials_unavailable');
        }
        $probe->check($current);
    }

    public function available(): bool
    {
        return $this->saved()!==null || config('mail.mailers.'.config('mail.default').'.transport')==='smtp';
    }

    /** Scope the override to actual mail dispatches, including in long-running workers. */
    public function send(Closure $dispatch): void
    {
        $saved=$this->saved();
        if ($saved===null) {
            if (config('mail.mailers.'.config('mail.default').'.transport')!=='smtp') throw new \RuntimeException('smtp_required');
            $dispatch();
            return;
        }
        $original=config('mail');
        $manager=app('mail.manager');
        try {
            config(['mail.default'=>'smtp','mail.mailers.smtp'=>[
                'transport'=>'smtp','scheme'=>$saved['security']==='tls'?'smtps':'smtp',
                'host'=>$saved['host'],'port'=>$saved['port'],'username'=>$saved['username'],'password'=>$saved['password'],
                'timeout'=>8,'require_tls'=>true,
            ],'mail.from'=>['address'=>$saved['from_address'],'name'=>$saved['from_name']]]);
            $manager->purge('smtp');
            $dispatch();
        } finally {
            config(['mail'=>$original]);
            $manager->purge('smtp');
        }
    }
}
