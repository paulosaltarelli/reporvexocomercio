<?php

namespace App\Fiscal;

use App\Models\BusinessProfile;
use App\Models\Tenant;
use App\Services\TenantConnection;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class PendingNfceCertificate
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public function stage(Tenant $tenant, string $expectedDocument, #[\SensitiveParameter] string $pfx): void
    {
        app(TenantConnection::class)->assertTenant($tenant);
        ProductInvoiceGate::assertEnabled(65);
        $document = TaxpayerDocument::normalize($expectedDocument);
        if (! $this->validDocument($document)) {
            throw ValidationException::withMessages(['document' => 'Informe o CNPJ do emitente cadastrado.']);
        }
        if ($pfx === '' || strlen($pfx) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['certificate' => 'Envie um arquivo A1/PFX de até 2 MB pela entrada padrão.']);
        }

        $db = DB::connection('tenant');
        $db->transaction(function () use ($db, $tenant, $document, $pfx) {
            $profile = BusinessProfile::whereKey(1)->lockForUpdate()->first();
            if (! $profile || TaxpayerDocument::normalize((string) $profile->document) !== $document) {
                throw ValidationException::withMessages(['document' => 'O CNPJ informado diverge do cadastro da unidade.']);
            }
            $path = $this->path($tenant, $document);
            $ciphertext = Crypt::encryptString($this->binding($tenant, $document).$pfx);
            $db->afterCommit(function () use ($path, $ciphertext) {
                $disk = Storage::disk('fiscal');
                $disk->makeDirectory(dirname($path));
                $lock = $this->lock($path);
                $temporary = $path.'.'.bin2hex(random_bytes(16)).'.tmp';
                try {
                    if (! $disk->put($temporary, $ciphertext, 'private') || ! $disk->move($temporary, $path)) {
                        throw new \RuntimeException('Não foi possível guardar o certificado preparado.');
                    }
                } finally {
                    fclose($lock);
                    $disk->delete($temporary);
                }
            });
        });
    }

    public function revision(Tenant $tenant, ?string $document): ?string
    {
        app(TenantConnection::class)->assertTenant($tenant);
        $document = TaxpayerDocument::normalize((string) $document);
        if (! $this->validDocument($document)) {
            return null;
        }

        // Renames publish whole ciphertexts atomically. This digest binds an open form to its version.
        $path = Storage::disk('fiscal')->path($this->path($tenant, $document));
        return is_file($path) ? (@hash_file('sha256', $path) ?: null) : null;
    }

    /** A successful explicit upload supersedes only the preparation the administrator selected. */
    public function discardIfCurrentAfterCommit(Tenant $tenant, string $document, string $revision): void
    {
        app(TenantConnection::class)->assertTenant($tenant);
        $path = $this->path($tenant, $document);
        $disk = Storage::disk('fiscal');
        DB::connection('tenant')->afterCommit(function () use ($disk, $path, $revision) {
            if (! $disk->exists($path)) {
                return;
            }
            try {
                $lock = $this->lock($path);
            } catch (ValidationException) {
                return;
            }
            try {
                if (hash_equals($revision, (string) @hash_file('sha256', $disk->path($path)))) {
                    $disk->delete($path);
                }
            } finally {
                fclose($lock);
            }
        });
    }

    /** The caller holds the tenant profile row lock in the credential transaction. */
    public function forActivation(Tenant $tenant, string $document, string $revision): string
    {
        app(TenantConnection::class)->assertTenant($tenant);
        $db = DB::connection('tenant');
        if (! $db->transactionLevel() || ! $this->validDocument($document)) {
            throw new \LogicException('A ativação exige o cadastro fiscal em transação.');
        }
        $disk = Storage::disk('fiscal');
        $path = $this->path($tenant, $document);
        if (! $disk->exists($path)) {
            $this->unavailable();
        }
        $lock = $this->lock($path);
        try {
            if (! $disk->exists($path) || $disk->size($path) > 2 * self::MAX_BYTES + 2048) {
                $this->unavailable();
            }
            $ciphertext = $disk->get($path);
            if (! hash_equals(hash('sha256', $ciphertext), $revision)) {
                $this->unavailable();
            }
            try {
                $contents = Crypt::decryptString($ciphertext);
            } catch (DecryptException) {
                $this->unavailable();
            }
            $binding = $this->binding($tenant, $document);
            if (! str_starts_with($contents, $binding)) {
                $this->unavailable();
            }
            $pfx = substr($contents, strlen($binding));
            if ($pfx === '' || strlen($pfx) > self::MAX_BYTES) {
                $this->unavailable();
            }
        } catch (\Throwable $e) {
            fclose($lock);
            throw $e;
        }

        // Retain the file on rollback. Keep the lock across the outer commit so cleanup can
        // never delete a newer import, even if a second request acquires the SQL row first.
        $db->afterRollBack(static fn () => fclose($lock));
        $db->afterCommit(static function () use ($disk, $path, $lock) {
            try {
                $disk->delete($path);
            } finally {
                fclose($lock);
            }
        });
        return $pfx;
    }

    private function path(Tenant $tenant, string $document): string
    {
        return 'tenants/'.$tenant->getKey().'/homologation/pending-nfce/'.hash('sha256', $document).'.enc';
    }

    private function binding(Tenant $tenant, string $document): string
    {
        return "NFC-e/65/homologation/{$tenant->getKey()}/{$document}\n";
    }

    private function validDocument(string $document): bool
    {
        return preg_match('/^[0-9]{14}$/D', $document) && TaxpayerDocument::cnpj($document);
    }

    /** @return resource */
    private function lock(string $path)
    {
        $lock = @fopen(Storage::disk('fiscal')->path($path.'.lock'), 'c+b');
        if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
            return $lock;
        }
        if ($lock) {
            fclose($lock);
        }
        throw ValidationException::withMessages(['certificate' => 'O certificado preparado está ocupado. Tente novamente.']);
    }

    private function unavailable(): never
    {
        throw ValidationException::withMessages(['certificate' => 'O certificado preparado mudou ou ficou indisponível. Reabra Minha empresa para conferir.']);
    }
}
