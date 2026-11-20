<?php

namespace App\Pix;

use App\Models\Tenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class SicoobClient
{
    public static function enabled(Tenant $tenant): bool
    {
        return !$tenant->is_demonstration && $tenant->id > 0
            && (string) $tenant->id === (string) config('sicoob.tenant_id');
    }

    public function configuration(): array
    {
        $settings = config('sicoob');
        foreach (['token_url', 'api_url'] as $field) {
            $url = parse_url((string) $settings[$field]);
            $host = strtolower($url['host'] ?? '');
            if (($url['scheme'] ?? '') !== 'https' || !str_ends_with($host, '.sicoob.com.br')
                || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
                || ($url['port'] ?? 443) !== 443) {
                throw self::error('Confira os endereços HTTPS do contrato Sicoob.');
            }
        }
        if (!filled($settings['client_id']) || !filled($settings['key'])
            || !is_readable((string) $settings['cert']) || !is_readable((string) $settings['private_key'])) {
            throw self::error('Configure a chave Pix, o cliente OAuth e o certificado bancário privado da empresa.');
        }
        return $settings;
    }

    public function fetch(string $txid, string $amount, bool $create): array
    {
        $settings = $this->configuration();
        $request = Http::acceptJson()->connectTimeout(4)->timeout(12)->withoutRedirecting()->withOptions([
            'cert' => $settings['cert'], 'ssl_key' => [$settings['private_key'], $settings['passphrase']],
        ]);
        try {
            $token = (clone $request)->asForm()->post($settings['token_url'], [
                'grant_type' => 'client_credentials', 'client_id' => $settings['client_id'],
                'scope' => $create ? 'cob.read cob.write' : 'cob.read',
            ]);
            if (!$token->successful() || !is_string($token->json('access_token')) || $token->json('access_token') === '') {
                throw self::error('O Sicoob não autenticou o cliente Pix. Confira o credenciamento e os escopos.');
            }
            $api = $request->withToken($token->json('access_token'))->withHeaders(['client_id' => $settings['client_id']]);
            $url = rtrim($settings['api_url'], '/').'/cob/'.$txid;
            $response = (clone $api)->get($url);
            if ($create && $response->status() === 404) {
                $response = $api->put($url, [
                    'calendario' => ['expiracao' => 3600],
                    'valor' => ['original' => $amount], 'chave' => $settings['key'],
                ]);
            }
            if (!$response->successful() || !is_array($response->json())) {
                throw self::error('Cobrança sem confirmação. Consulte novamente pelo mesmo identificador.');
            }
            return $response->json();
        } catch (ConnectionException) {
            throw self::error('Sem resposta do Sicoob. Consulte a cobrança pelo mesmo identificador.');
        }
    }

    public static function error(string $message): ValidationException
    {
        return ValidationException::withMessages(['pix' => $message]);
    }
}
