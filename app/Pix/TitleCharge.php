<?php

namespace App\Pix;

use App\Models\FinancialEntry;
use App\Models\OperationRecord;
use App\Models\Tenant;
use App\Services\FinancialService;
use App\Services\TenantConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class TitleCharge
{
    public function __construct(private SicoobClient $client) {}

    private function record(string $action, int $entryId): ?OperationRecord
    {
        return OperationRecord::where('action', $action)->where('after->entry_id', $entryId)->first();
    }

    private function save(string $action, int $actor, array $data): void
    {
        OperationRecord::create(['action' => $action, 'version' => 0, 'user_id' => $actor,
            'before' => [], 'after' => $data, 'created_at' => now()]);
    }

    public function sync(Tenant $tenant, int $entryId, int $actor, bool $create): array
    {
        app(TenantConnection::class)->assertTenant($tenant);
        abort_unless(SicoobClient::enabled($tenant), 404);
        $this->client->configuration();
        $db = DB::connection('tenant');
        $account = hash('sha256', json_encode([config('sicoob.key'), config('sicoob.api_url'), config('sicoob.client_id')], JSON_THROW_ON_ERROR));
        $intent = $db->transaction(function () use ($entryId, $actor, $create, $account) {
            $entry = FinancialEntry::lockForUpdate()->findOrFail($entryId);
            abort_unless($entry->direction === 'in', 422);
            $existing = $this->record('sicoob_pix_charge', $entryId);
            if ($existing) {
                return $existing->after;
            }
            if (!$create || $entry->cancelled_at || bccomp($entry->balance, '0', 2) <= 0
                || !preg_match('/^\d{1,10}\.\d{2}$/D', $entry->balance)) {
                throw SicoobClient::error('É necessário um título a receber aberto e com saldo compatível com a API Pix.');
            }
            $data = ['entry_id' => $entryId, 'amount' => $entry->balance,
                'txid' => str_replace('-', '', (string) Str::uuid()), 'account' => $account];
            $this->save('sicoob_pix_charge', $actor, $data);
            return $data;
        }, 3);
        if (!hash_equals($intent['account'], $account)) {
            throw SicoobClient::error('A cobrança pertence a outra configuração bancária. Confira a conta original.');
        }
        $entry = FinancialEntry::findOrFail($entryId);
        $canCreate = $create && !$entry->cancelled_at && bccomp($entry->balance, $intent['amount'], 2) === 0;
        $bank = $this->client->fetch($intent['txid'], $intent['amount'], $canCreate);
        $result = $this->inspect($bank, $intent);
        if ($result['status'] === 'Recebido') {
            $db->transaction(function () use ($entryId, $actor, $result, $intent) {
                $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:pix:'.$result['endToEndId'])->toString();
                $previous = $this->record('sicoob_pix_settled', $entryId);
                if ($previous && $previous->after['endToEndId'] !== $result['endToEndId']) {
                    throw SicoobClient::error('O identificador bancário diverge da conciliação anterior.');
                }
                if ($previous) {
                    return;
                }
                // The immutable issuer makes concurrent reconciliations by different operators replayable.
                $issuer = $this->record('sicoob_pix_charge', $entryId)->user_id;
                $payment = app(FinancialService::class)->pay($entryId, $intent['amount'], $result['paid_on'], 'pix', $issuer, $key);
                // pay() serializes by sale/title. Read the current committed settlement after its locks.
                $settled = OperationRecord::where('action', 'sicoob_pix_settled')->where('after->entry_id', $entryId)->lockForUpdate()->first();
                if (!$settled) {
                    $this->save('sicoob_pix_settled', $actor, ['entry_id' => $entryId,
                        'endToEndId' => $result['endToEndId'], 'payment_id' => $payment->id, 'txid' => $intent['txid']]);
                }
            }, 3);
        } elseif ($entry->fresh()->balance !== $intent['amount']) {
            throw SicoobClient::error('Saldo alterado após a reserva do Pix. Confira o título e a cobrança no Sicoob.');
        }
        return $result + ['txid' => $intent['txid'], 'amount' => $intent['amount']];
    }

    public function inspect(array $bank, array $intent): array
    {
        if (($bank['txid'] ?? '') !== $intent['txid'] || ($bank['valor']['original'] ?? '') !== $intent['amount']
            || ($bank['chave'] ?? '') !== config('sicoob.key')) {
            throw SicoobClient::error('Identificação, chave ou valor da cobrança divergente. Confira no banco.');
        }
        if (($bank['status'] ?? '') === 'ATIVA' && empty($bank['pix'])) {
            $created = $this->timestamp($bank['calendario']['criacao'] ?? null);
            $lifetime = $bank['calendario']['expiracao'] ?? null;
            if (!is_int($lifetime) || $lifetime < 1) {
                throw SicoobClient::error('Prazo da cobrança inválido.');
            }
            $expires = $created->addSeconds($lifetime);
            if ($expires->isPast()) {
                return ['status' => 'Expirada'];
            }
            $code = $bank['pixCopiaECola'] ?? '';
            if (!is_string($code) || $code === '' || strlen($code) > 512) {
                throw SicoobClient::error('O Sicoob não retornou um código Pix válido.');
            }
            return ['status' => 'Aguardando pagamento', 'code' => $code, 'expires' => $expires];
        }
        $receipts = $bank['pix'] ?? [];
        $pix = $receipts[0] ?? [];
        if (($bank['status'] ?? '') !== 'CONCLUIDA' || !is_array($receipts) || count($receipts) !== 1 || !is_array($pix)
            || ($pix['txid'] ?? '') !== $intent['txid'] || ($pix['valor'] ?? '') !== $intent['amount']
            || !preg_match('/^E[A-Za-z0-9]{31}$/D', (string) ($pix['endToEndId'] ?? '')) || !empty($pix['devolucoes'])) {
            throw SicoobClient::error('Cobrança sem recebimento integral conciliável. Confira a situação e eventuais devoluções no Sicoob.');
        }
        $paid = $this->timestamp($pix['horario'] ?? null);
        if ($paid->isFuture()) {
            throw SicoobClient::error('A data de recebimento informada pelo banco está no futuro.');
        }
        return ['status' => 'Recebido', 'paid_on' => $paid->toDateString(), 'endToEndId' => $pix['endToEndId']];
    }

    private function timestamp(mixed $value): CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            try {
                $date = new \DateTimeImmutable($value);
                if (!\DateTimeImmutable::getLastErrors()) {
                    return CarbonImmutable::instance($date)->setTimezone(config('app.timezone'));
                }
            } catch (\Exception) {
                // Invalid calendar value.
            }
        }
        throw SicoobClient::error('O banco retornou data inválida para a cobrança.');
    }
}
