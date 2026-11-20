<?php

namespace App\Platform\Billing;

use App\Models\Tenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class C6Bank implements C6Gateway
{
    public function __construct(private ?array $profile = null) {}

    public function forTenant(Tenant $tenant): self
    {
        $profile = config('c6pix');
        if ($tenant->is_demonstration && ($profile['environment'] ?? '') !== 'sandbox') {
            $profile = $profile['sandbox'] ?? [];
        }
        $environment = $tenant->is_demonstration ? 'sandbox' : 'production';
        if (($profile['environment'] ?? '') !== $environment) {
            throw self::error('O ambiente bancário configurado não corresponde ao tipo desta empresa.');
        }
        return new self($profile);
    }

    public static function enabledForTenant(Tenant $tenant): bool
    {
        try {
            (new self())->forTenant($tenant)->settings();
            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public function receivingKey(): string
    {
        return $this->settings()['key'];
    }

    private function settings(): array
    {
        $config = $this->profile ?? config('c6pix');
        if (empty($config['enabled']) || !filled($config['approval_reference'] ?? '')
            || !in_array($config['environment'], ['sandbox', 'production'], true)) {
            throw self::error('Pix C6 indisponível. Confira a habilitação e o ambiente da plataforma.');
        }
        foreach (['api_url', 'token_url'] as $name) {
            $url = parse_url((string) $config[$name]);
            $host = strtolower($url['host'] ?? '');
            if ($config['environment'] === 'sandbox' && !str_contains($host, 'sandbox')) {
                throw self::error('Os endereços de autenticação e Pix da demonstração devem identificar o sandbox do C6.');
            }
            if ($config['environment'] === 'production' && preg_match('/sandbox|homolog|(?:^|[.-])(?:hml|test|qa|uat|dev)(?:[.-]|$)/i', $host)) {
                throw self::error('Use os endereços bancários de produção confirmados pelo C6 para a conta recebedora.');
            }
            if (($url['scheme'] ?? '') !== 'https' || !isset($url['path']) || !preg_match('/^[a-z0-9.-]+$/D', $host)
                || (!str_ends_with($host, '.c6bank.com.br') && !str_ends_with($host, '.c6bank.info'))
                || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
                || ($url['port'] ?? 443) !== 443) {
                throw self::error('Configure os endereços HTTPS exatos fornecidos pelo C6 para a conta.');
            }
        }
        if (!in_array($config['auth_method'], ['client_secret_post','client_secret_basic'], true)
            || !filled($config['client_id']) || !filled($config['client_secret']) || !filled($config['key'])
            || !is_file((string) $config['cert']) || !is_readable($config['cert'])
            || !is_file((string) $config['private_key']) || !is_readable($config['private_key'])) {
            throw self::error('Configure as credenciais OAuth, mTLS e chave Pix da conta recebedora C6.');
        }
        return $config;
    }

    public function account(): string
    {
        $c = $this->settings();
        return hash('sha256', implode("\n", [$c['environment'], $c['api_url'], $c['client_id'], $c['key']]));
    }

    private function authenticated(array $c): PendingRequest
    {
        $request = Http::acceptJson()->timeout(12)->connectTimeout(4)->withoutRedirecting()->withOptions([
            'cert' => $c['cert'], 'ssl_key' => [$c['private_key'], $c['passphrase']],
        ]);
        $credentials = (clone $request)->asForm();
        $form = ['grant_type' => 'client_credentials'];
        if (filled($c['scope'])) {
            $form['scope'] = $c['scope'];
        }
        if ($c['auth_method'] === 'client_secret_basic') {
            $credentials->withBasicAuth($c['client_id'], $c['client_secret']);
        } else {
            $form += ['client_id' => $c['client_id'], 'client_secret' => $c['client_secret']];
        }
        $response = $credentials->post($c['token_url'], $form);
        $token = $response->json('access_token');
        if (!$response->successful() || !is_string($token) || $token === '') {
            throw self::error('O C6 não autenticou a conta recebedora. Confira a configuração com o banco.');
        }
        return $request->withToken($token);
    }

    public function charge(string $txid, string $amount, bool $create): array
    {
        if (!preg_match('/^[a-zA-Z0-9]{26,35}$/D', $txid) || !preg_match('/^\d{1,10}\.\d{2}$/D', $amount)) {
            throw self::error('Identificador ou valor da cobrança Pix inválido.');
        }
        $c = $this->settings();
        $url = rtrim($c['api_url'], '/').'/cob/'.$txid;
        try {
            $api = $this->authenticated($c);
            $response = (clone $api)->get($url);
            if ($create && $response->status() === 404) {
                $response = $api->put($url, ['calendario' => ['expiracao' => 3600],
                    'valor' => ['original' => $amount], 'chave' => $c['key']]);
            }
        } catch (ConnectionException) {
            throw self::error('Sem comunicação com o C6. Consulte novamente pelo mesmo identificador.');
        }
        if (!$response->successful() || !is_array($response->json())) {
            throw self::error('Cobrança sem resposta conclusiva do C6. Repita a consulta pelo mesmo identificador.');
        }
        return $response->json();
    }

    public function receipt(string $endToEndId): array
    {
        if (!preg_match('/^E[A-Za-z0-9]{31}$/D', $endToEndId)) {
            throw self::error('Identificação bancária do recebimento inválida.');
        }
        $c = $this->settings();
        try {
            $response = $this->authenticated($c)->get(rtrim($c['api_url'], '/').'/pix/'.$endToEndId);
        } catch (ConnectionException) {
            throw self::error('Sem confirmação do recebimento no extrato Pix do C6. Repita a consulta.');
        }
        if (!$response->successful() || !is_array($response->json())) {
            throw self::error('O C6 ainda não confirmou o recebimento pelo identificador de ponta a ponta.');
        }
        return $response->json();
    }

    public static function error(string $message): ValidationException
    {
        return ValidationException::withMessages(['pix' => $message]);
    }
}
