<?php
namespace App\Fiscal;

use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/** Decode old PKCS#12 encryption in an isolated process, then retain modern PKCS#12. */
final class PfxCompatibility
{
    public function normalize(#[\SensitiveParameter] string $pfx,#[\SensitiveParameter] string $password): string
    {
        if($pfx===''||strlen($pfx)>2097152||strlen($password)>1024)$this->invalid();
        while(openssl_error_string()!==false){}
        $keys=[];
        if(@openssl_pkcs12_read($pfx,$keys,$password))return $pfx;
        $unsupported=false;
        while(($error=openssl_error_string())!==false)if(str_contains(strtolower($error),'unsupported'))$unsupported=true;
        // Wrong passwords and malformed files must never bypass PKCS#12 integrity checks.
        if(!$unsupported)$this->invalid();
        try {
            $process=new Process(['/usr/bin/php','-c','/opt/reporvexo-tools/php/cli.ini',__DIR__.'/pfx-worker.php'],base_path(),['OPENSSL_CONF'=>config_path('openssl-pfx.cnf')],json_encode(['pfx'=>base64_encode($pfx),'password'=>$password],JSON_THROW_ON_ERROR),10);
            $process->run();
            if(!$process->isSuccessful())$this->invalid();
            $result=json_decode($process->getOutput(),true,8,JSON_THROW_ON_ERROR);
            $modern=base64_decode($result['pfx']??'',true);$decoded=[];
            if(!is_string($modern)||strlen($modern)>3145728||!@openssl_pkcs12_read($modern,$decoded,$password)||!@openssl_x509_check_private_key($decoded['cert'],$decoded['pkey'])||!hash_equals((string)($result['fingerprint']??''),(string)openssl_x509_fingerprint($decoded['cert'],'sha256')))$this->invalid();
            return $modern;
        } catch (ValidationException $e) { throw $e; }
        catch (\Throwable) { $this->invalid(); }
        finally { while(openssl_error_string()!==false){} }
    }
    private function invalid(): never
    {
        throw ValidationException::withMessages(['certificate'=>'Não foi possível abrir o A1/PFX. Confira se o arquivo é um certificado A1 (.pfx ou .p12) completo e se a senha está correta.']);
    }
}
