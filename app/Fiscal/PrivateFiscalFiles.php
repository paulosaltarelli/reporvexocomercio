<?php

namespace App\Fiscal;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PrivateFiscalFiles
{
    public function put(int $tenantId, int $documentId, string $kind, #[\SensitiveParameter] string $bytes): void
    {
        $sha = hash('sha256', $bytes);
        $table = DB::connection('tenant')->table('fiscal_files');
        if ((clone $table)->where('document_id', $documentId)->where('kind', $kind)->where('sha256', $sha)->exists()) {
            return;
        }
        $path = 'tenants/'.$tenantId.'/homologation/'.$documentId.'/'.bin2hex(random_bytes(16)).'.enc';
        Storage::disk('fiscal')->put($path, Crypt::encryptString(base64_encode($bytes)), ['visibility' => 'private']);
        $table->insert(['document_id' => $documentId, 'kind' => $kind, 'private_path' => $path, 'sha256' => $sha, 'bytes' => strlen($bytes), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function get(int $tenantId, int $documentId, string $kind): string
    {
        $file = DB::connection('tenant')->table('fiscal_files')->where('document_id', $documentId)->where('kind', $kind)->latest('id')->first();
        abort_unless($file && preg_match('#^tenants/'.$tenantId.'/homologation/'.$documentId.'/[a-f0-9]{32}\.enc$#D', $file->private_path), 404);
        $bytes = base64_decode(Crypt::decryptString(Storage::disk('fiscal')->get($file->private_path)), true);
        abort_unless(is_string($bytes) && hash_equals($file->sha256, hash('sha256', $bytes)), 409, 'Arquivo fiscal com integridade inválida.');

        return $bytes;
    }
}
