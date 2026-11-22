<?php

namespace App\Platform;

use App\Access\Permissions;
use App\Models\Tenant;
use Illuminate\Http\Request;

final class ContractAccess
{
    public const MODULES = [
        'sales' => 'Frente de caixa e vendas',
        'documents' => 'Orçamentos e ordens de serviço',
        'catalog' => 'Cadastros comerciais',
        'stock' => 'Estoque e preços',
        'purchases' => 'Compras',
        'finance' => 'Financeiro e caixa',
        'fiscal' => 'Fiscal',
        'commissions' => 'Comissões',
        'reports' => 'Relatórios e indicadores',
    ];

    /** A previous contract can still have outstanding installments after its end date. */
    public function restricted(Tenant $tenant): array
    {
        $today = now()->toDateString();
        $policies = Contract::query()
            ->where('tenant_id', $tenant->id)
            ->where('start_date', '<=', $today)
            ->whereNotNull('restricted_modules')
            ->whereExists(function ($installments) use ($today) {
                $installments->selectRaw('1')->from('platform_contract_installments as installment')
                    ->whereColumn('installment.contract_id', 'platform_contracts.id')
                    ->whereColumn('installment.tenant_id', 'platform_contracts.tenant_id')
                    ->whereNull('installment.cancelled_at')
                    ->whereRaw('DATEDIFF(?, installment.due_date) > platform_contracts.overdue_grace_days', [$today])
                    ->whereColumn('installment.paid_amount', '<', 'installment.amount');
            })
            ->get(['restricted_modules']);

        $blocked = [];
        foreach ($policies as $contract) {
            foreach ($contract->restricted_modules ?? [] as $module) {
                if (isset(self::MODULES[$module])) $blocked[$module] = self::MODULES[$module];
            }
        }
        return $blocked;
    }

    /** Permissions identify controllers and actions, including unnamed POST and quick-create routes. */
    public function modulesFor(Request $request): array
    {
        if ($request->routeIs('business.public-catalog') && $request->input('visibility') === 'public') return ['catalog'];
        if ($request->routeIs('restaurant.activity')) return ['sales'];
        $permission = Permissions::request($request);
        return match ($permission) {
            'documents.invoice' => ['documents', 'sales'],
            'sales.receive','restaurant.receive','restaurant.refund' => ['sales', 'finance'],
            'restaurant.catalog.manage' => ['catalog'],
            'commissions.pay' => ['commissions', 'finance'],
            'administration' => match (true) {
                $request->routeIs('payment.methods*', 'payment.brands.*') => ['finance'],
                $request->routeIs('setup.fiscal.continue') => ['fiscal'],
                default => [],
            },
            default => match (strtok($permission, '.')) {
                'desk', 'sales', 'restaurant', 'supermarket', 'delivery' => ['sales'],
                'documents' => ['documents'],
                'products', 'customers', 'suppliers', 'groups', 'sellers' => ['catalog'],
                'stock', 'prices' => ['stock'],
                'purchases' => ['purchases'],
                'finance', 'cash', 'cashflow' => ['finance'],
                'fiscal' => ['fiscal'],
                'commissions' => ['commissions'],
                'reports', 'home' => ['reports'],
                default => [],
            },
        };
    }
}
