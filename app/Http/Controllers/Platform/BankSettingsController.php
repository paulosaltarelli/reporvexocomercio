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

        return response()->view('platform.bank-settings', [
            'banks' => BankEnvironments::BANKS,
            'environments' => BankEnvironments::ENVIRONMENTS,
            'profiles' => $profiles,
            'ready' => ['c6' => [
                'sandbox' => C6Bank::enabledForTenant(new Tenant(['is_demonstration' => true])),
                'production' => C6Bank::enabledForTenant(new Tenant(['is_demonstration' => false])),
            ]],
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
        $actor = $request->attributes->get('platformAdmin');
        DB::connection('central')->transaction(function () use ($banks, $bank, $environment, $flags, $actor) {
            $now = now();
            $banks->table()->upsert(
                [$flags + ['bank' => $bank, 'environment' => $environment, 'created_at' => $now, 'updated_at' => $now]],
                ['bank', 'environment'],
                [...array_keys($flags), 'updated_at'],
            );
            PlatformAudit::create([
                'platform_admin_id' => $actor->id,
                'actor_name' => $actor->name,
                'action' => 'platform.bank_environment_updated',
                'changes' => ['bank' => $bank, 'environment' => $environment] + $flags,
            ]);
        }, 3);

        return redirect()->route('platform.settings.banks.index')->with('success', 'Configuração bancária salva.');
    }
}
