<?php

namespace App\Fiscal;

use Illuminate\Support\Facades\Http;

final class FocusClient
{
    public function request(string $method, string $path, #[\SensitiveParameter] string $token, #[\SensitiveParameter] array $data = [], bool $management = false): array
    {
        // Endpoints are protocol constants, never supplied by a company/browser.
        $base = $management ? 'https://api.focusnfe.com.br' : 'https://homologacao.focusnfe.com.br';
        if ($token === '') {
            throw new FiscalTransportException('credential_missing');
        }
        try {
            $request = Http::withBasicAuth($token, '')->acceptJson()->asJson()->connectTimeout(10)->timeout(45)->withoutRedirecting();
            $response = $request->send($method, $base.'/v2'.$path, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
            if ($response->status() === 404) {
                throw new FiscalTransportException('reference_not_found');
            }
            if (! $response->successful()) {
                throw new FiscalTransportException('provider_http_'.$response->status());
            }
            $json = $response->json();
            if (! is_array($json)) {
                throw new FiscalTransportException('provider_invalid_response');
            }

            return $json;
        } catch (FiscalTransportException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new FiscalTransportException('provider_connection_uncertain');
        }
    }

    public function download(string $path, #[\SensitiveParameter] string $token): string
    {
        if (! preg_match('#^/(?:arquivos[_a-z]*/|notas_fiscais_consumidor/)[a-zA-Z0-9_./-]+$#D', $path) || str_contains($path, '..')) {
            throw new FiscalTransportException('unsafe_file_path');
        }
        try {
            $response = Http::withBasicAuth($token, '')->connectTimeout(10)->timeout(45)->withoutRedirecting()->get('https://homologacao.focusnfe.com.br'.$path);
            if (! $response->successful() || strlen($response->body()) > 10 * 1024 * 1024) {
                throw new FiscalTransportException('file_unavailable');
            }

            return $response->body();
        } catch (FiscalTransportException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new FiscalTransportException('file_connection_uncertain');
        }
    }
}
