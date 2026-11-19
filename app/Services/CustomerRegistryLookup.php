<?php

namespace App\Services;

use App\Fiscal\TaxpayerDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class CustomerRegistryLookup
{
    public function find(string $document): array
    {
        $cnpj = TaxpayerDocument::normalize($document);
        if (! TaxpayerDocument::cnpj($cnpj)) {
            throw ValidationException::withMessages(['document' => 'Informe um CNPJ válido.']);
        }
        if (! ctype_digit($cnpj)) {
            throw ValidationException::withMessages(['document' => 'Preencha manualmente este CNPJ alfanumérico. A consulta disponível atende CNPJs numéricos.']);
        }

        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(7)->withoutRedirecting()
                ->get('https://brasilapi.com.br/api/cnpj/v1/'.$cnpj);
        } catch (ConnectionException) {
            abort(503, 'Consulta indisponível. Tente novamente ou preencha manualmente.');
        }

        if ($response->notFound()) {
            abort(404, 'CNPJ não encontrado na BrasilAPI. Confira os dígitos ou preencha manualmente.');
        }
        if (! $response->successful()) {
            abort(503, 'Consulta indisponível. Tente novamente ou preencha manualmente.');
        }

        $data = $response->json();
        if (! is_array($data) || ($data['cnpj'] ?? null) !== $cnpj || empty($data['razao_social'])) {
            abort(503, 'A consulta retornou dados incompletos. Preencha manualmente.');
        }

        $mapping = [
            'name' => ['razao_social', 180],
            'trade_name' => ['nome_fantasia', 180],
            'legal_nature' => ['natureza_juridica', 180],
            'opened_on' => ['data_inicio_atividade', 10],
            'registration_status' => ['descricao_situacao_cadastral', 60],
            'primary_cnae' => ['cnae_fiscal', 12],
            'fiscal_street' => ['logradouro', 60],
            'fiscal_number' => ['numero', 60],
            'fiscal_complement' => ['complemento', 60],
            'fiscal_district' => ['bairro', 60],
            'fiscal_city' => ['municipio', 60],
            'fiscal_state' => ['uf', 2],
            'fiscal_postal_code' => ['cep', 9],
            'fiscal_municipality_code' => ['codigo_municipio_ibge', 7],
            'email' => ['email', 180],
            'phone' => ['ddd_telefone_1', 30],
        ];
        $fields = [];
        foreach ($mapping as $field => [$key, $limit]) {
            $value = $data[$key] ?? null;
            if ((is_string($value) || is_int($value)) && trim((string) $value) !== '') {
                $fields[$field] = mb_substr(trim((string) $value), 0, $limit);
            }
        }
        if (isset($fields['email']) && ! filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
            unset($fields['email']);
        }
        foreach (['primary_cnae' => 7, 'fiscal_postal_code' => 8, 'fiscal_municipality_code' => 7] as $field => $length) {
            $value = preg_replace('/\D/', '', $fields[$field] ?? '');
            if ($field === 'primary_cnae' && is_int($data['cnae_fiscal'] ?? null) && (int) $value > 0) {
                $value = str_pad($value, 7, '0', STR_PAD_LEFT);
            }
            if (strlen($value) === $length && (int) $value > 0) {
                $fields[$field] = $value;
            } else {
                unset($fields[$field]);
            }
        }

        return $fields;
    }
}
