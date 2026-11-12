<?php

namespace App\Fiscal;

use App\Models\FiscalCredential;
use NFePHP\Common\Certificate;
use NFePHP\Common\Soap\SoapBase;
use NFePHP\NFe\Tools;

/** Official endpoint resolution belongs exclusively to the pinned NFePHP service table. */
class SefazTransport
{
    protected Tools $tools;

    public function __construct(array $issuer, array $configuration, #[\SensitiveParameter] FiscalCredential $credential)
    {
        try {
            $secrets = $credential->secrets ?? [];
            $pfx = base64_decode((string) ($secrets['pfx'] ?? ''), true);
            if ($pfx === false || $pfx === '') {
                throw new FiscalTransportException('certificate_missing');
            }
            $certificate = Certificate::readPfx($pfx, (string) ($secrets['certificate_password'] ?? ''));
            $cnpj = preg_replace('/\D/', '', (string) ($issuer['document'] ?? ''));
            $pem = openssl_x509_read((string) $certificate);
            $fingerprint = $pem ? openssl_x509_fingerprint($pem, 'sha256') : false;
            if ($certificate->getValidFrom()->getTimestamp() > time() || $certificate->isExpired() || $certificate->getCnpj() !== $cnpj || ! $fingerprint || ! hash_equals(strtolower(str_replace(':', '', (string) $credential->certificate_fingerprint)), strtolower($fingerprint))) {
                throw new FiscalTransportException('certificate_context_invalid');
            }
            if (! ($secrets['csc'] ?? '') || ! preg_match('/^[0-9]{1,6}$/D', (string) ($secrets['csc_id'] ?? ''))) {
                throw new FiscalTransportException('csc_missing');
            }
            $config = [
                'atualizacao' => date('Y-m-d H:i:s'), 'tpAmb' => 2,
                'razaosocial' => (string) ($issuer['legal_name'] ?? ''),
                'siglaUF' => (string) ($issuer['state'] ?? ''), 'cnpj' => $cnpj,
                'schemes' => FiscalSchema::PACKAGE, 'versao' => '4.00',
                'tokenIBPT' => '', 'CSC' => (string) $secrets['csc'],
                'CSCid' => (string) $secrets['csc_id'],
            ];
            $this->tools = new Tools(json_encode($config, JSON_THROW_ON_ERROR), $certificate);
            $this->tools->model(65);
            $soap = new VerifiedSefazSoap($certificate);
            $soap->timeout(30);
            $this->tools->loadSoapClass($soap);
        } catch (FiscalTransportException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new FiscalTransportException('sefaz_configuration_invalid');
        }
    }

    public function sign(#[\SensitiveParameter] string $xml): string
    {
        try {
            return $this->tools->signNFe($xml, 1);
        } catch (\Throwable) {
            throw new FiscalTransportException('nfce_signing_or_schema_invalid');
        }
    }

    /** @return array{request:string,response:string} */
    public function authorize(#[\SensitiveParameter] string $xml, string $key): array
    {
        $this->key($key);
        return $this->perform(fn () => $this->tools->sefazEnviaLote([$xml], substr($key, -15), 1));
    }

    public function consult(string $key): array
    {
        $this->key($key);
        return $this->perform(fn () => $this->tools->sefazConsultaChave($key, 2));
    }

    public function cancel(string $key, string $protocol, string $reason): array
    {
        $this->key($key);
        if (! preg_match('/^[0-9]{15}$/D', $protocol) || mb_strlen(trim($reason)) < 15 || mb_strlen(trim($reason)) > 255) {
            throw new FiscalTransportException('cancellation_arguments_invalid');
        }
        return $this->perform(fn () => $this->tools->sefazCancela($key, $reason, $protocol));
    }

    private function perform(callable $operation): array
    {
        try {
            $response = $operation();
            return ['request' => (string) $this->tools->lastRequest, 'response' => $response];
        } catch (\Throwable) {
            // The request may have reached SEFAZ. Never expose SOAP/PFX details or infer rejection.
            throw new FiscalTransportException('sefaz_connection_uncertain');
        }
    }

    private function key(string $key): void
    {
        if (! preg_match('/^[0-9]{44}$/D', $key) || substr($key, 20, 2) !== '65') {
            throw new FiscalTransportException('access_key_invalid');
        }
    }
}

/** NFePHP's stock SoapCurl disables peer verification; this transport deliberately replaces it. */
final class VerifiedSefazSoap extends SoapBase
{
    public function send($url, $operation = '', $action = '', $soapver = SOAP_1_2, $parameters = [], $namespaces = [], $request = '', $soapheader = null)
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! defined('CURLOPT_SSLCERT_BLOB') || ! defined('CURLOPT_SSLKEY_BLOB')) {
            throw new FiscalTransportException('verified_tls_unavailable');
        }
        $envelope = $this->makeEnvelopeSoap($request, $namespaces, $soapver, $soapheader);
        $handle = curl_init($url);
        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $envelope,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $this->soaptimeout,
                CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_SSLCERT_BLOB => (string) $this->certificate,
                CURLOPT_SSLKEY_BLOB => (string) $this->certificate->privateKey,
                CURLOPT_HTTPHEADER => ['Content-Type: application/soap+xml; charset=utf-8; action="'.$action.'"', 'Content-Length: '.strlen($envelope)],
            ]);
            $response = curl_exec($handle);
            if (! is_string($response) || curl_errno($handle) !== 0 || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200 || strlen($response) > 8 * 1024 * 1024) {
                throw new FiscalTransportException('sefaz_connection_uncertain');
            }
            return $response;
        } finally {
            curl_close($handle);
        }
    }
}
