<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Platform\Billing\BankEnvironments;
use App\Platform\Billing\C6Bank;
use App\Models\Tenant;
use App\Platform\PlatformAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BankSettingsController extends Controller
{
    public function index(BankEnvironments $banks)
    {
        $profiles = $banks->table()->get()->keyBy(fn ($row) => $row->bank.':'.$row->environment);

        $connections = [];
        $credentialNames = [];
        $ready = [];
        foreach (BankEnvironments::ENVIRONMENTS as $environment => $label) {
            $connections[$environment] = $banks->connection($profiles->get('c6:'.$environment));
            $credentialNames[$environment] = array_keys(C6Bank::credentialSets($environment));
            $ready[$environment] = C6Bank::enabledForTenant(
                new Tenant(['is_demonstration' => $environment === 'sandbox']), $connections[$environment],
            );
        }

        return response()->view('platform.bank-settings', [
            'banks' => BankEnvironments::BANKS,
            'environments' => BankEnvironments::ENVIRONMENTS,
            'profiles' => $profiles,
            'ready' => ['c6' => $ready],
            'connections' => ['c6' => $connections],
            'credentialNames' => ['c6' => $credentialNames],
            'issuancePaused' => config('c6pix.issuance_paused'),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, string $bank, string $environment, BankEnvironments $banks)
    {
        abort_unless(isset(BankEnvironments::BANKS[$bank], BankEnvironments::ENVIRONMENTS[$environment]), 404);
        $flags = $request->validate([
            'enabled' => 'required|boolean',
            'issue' => 'required|boolean',
            'reconcile' => 'required|boolean',
            'auto_issue' => 'required|boolean',
        ]);
        if ($flags['auto_issue'] && ($environment !== 'production' || !$flags['issue'] || !$flags['reconcile'])) {
            throw ValidationException::withMessages(['auto_issue' => 'A emissão automática exige produção, emissão e conciliação habilitadas.']);
        }
        $parameters = $request->validate([
            'api_url' => 'nullable|string|max:512',
            'token_url' => 'nullable|string|max:512',
            'auth_method' => 'nullable|in:client_secret_basic,client_secret_post',
            'scope' => 'nullable|string|max:255|not_regex:/[[:cntrl:]]/',
            'credential_set' => 'nullable|string|max:100',
        ]);
        foreach (['api_url', 'token_url'] as $field) {
            if (isset($parameters[$field]) && !C6Bank::validEndpoint($parameters[$field], $environment)) {
                throw ValidationException::withMessages([$field => 'Informe uma URL HTTPS do C6 correspondente a este ambiente.']);
            }
        }
        if (isset($parameters['credential_set']) && !isset(C6Bank::credentialSets($environment)[$parameters['credential_set']])) {
            throw ValidationException::withMessages(['credential_set' => 'Selecione um vínculo de credenciais deste ambiente.']);
        }
        $values = $flags;
        if ($parameters !== []) {
            $values['connection_settings'] = json_encode(array_filter($parameters, fn ($value) => $value !== null), JSON_THROW_ON_ERROR);
        }
        $actor = $request->attributes->get('platformAdmin');
        DB::connection('central')->transaction(function () use ($banks, $bank, $environment, $flags, $values, $actor) {
            $now = now();
            $banks->table()->upsert(
                [$values + ['bank' => $bank, 'environment' => $environment, 'created_at' => $now, 'updated_at' => $now]],
                ['bank', 'environment'],
                [...array_keys($values), 'updated_at'],
            );
            PlatformAudit::create([
                'platform_admin_id' => $actor->id,
                'actor_name' => $actor->name,
                'action' => 'platform.bank_environment_updated',
                'changes' => ['bank' => $bank, 'environment' => $environment, 'connection_updated' => isset($values['connection_settings'])] + $flags,
            ]);
        }, 3);

        return redirect()->route('platform.settings.banks.index')->with('success', 'Configuração bancária salva.');
    }
}
