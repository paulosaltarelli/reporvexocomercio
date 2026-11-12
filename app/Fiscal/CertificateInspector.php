<?php

namespace App\Fiscal;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use NFePHP\Common\Certificate;

final class CertificateInspector
{
    public function inspect(#[\SensitiveParameter] string $pfx, #[\SensitiveParameter] string $password, string $cnpj): array
    {
        try {
            $certificate = @Certificate::readPfx($pfx, $password);
            $keys = [];
            if (! @openssl_pkcs12_read($pfx, $keys, $password) || ! @openssl_x509_check_private_key($keys['cert'], $keys['pkey'])) {
                throw new \RuntimeException;
            }
            if (! hash_equals(TaxpayerDocument::normalize($cnpj), TaxpayerDocument::normalize((string) $certificate->getCnpj()))) {
                throw ValidationException::withMessages(['certificate' => 'O CNPJ do certificado deve corresponder ao emitente cadastrado.']);
            }
            if ($certificate->getValidFrom()->getTimestamp() > time() || $certificate->getValidTo()->getTimestamp() <= time()) {
                throw ValidationException::withMessages(['certificate' => 'Certificado vencido ou ainda fora do período de validade.']);
            }
            $parsed = openssl_x509_parse($keys['cert']);
            if (! str_contains($parsed['extensions']['keyUsage'] ?? '', 'Digital Signature')) {
                throw ValidationException::withMessages(['certificate' => 'Certificado sem uso de assinatura digital.']);
            }

            return ['certificate_fingerprint' => openssl_x509_fingerprint($keys['cert'], 'sha256'), 'certificate_document' => TaxpayerDocument::normalize((string) $certificate->getCnpj()), 'valid_from' => Carbon::instance($certificate->getValidFrom())->setTimezone(config('app.timezone')), 'valid_until' => Carbon::instance($certificate->getValidTo())->setTimezone(config('app.timezone'))];
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['certificate' => 'Não foi possível abrir o A1/PFX. Confira arquivo e senha. Arquivos com algoritmo legado precisam ser reexportados com criptografia atual.']);
        } finally {
            while (openssl_error_string() !== false) {
            }
        }
    }
}
