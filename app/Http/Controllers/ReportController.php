<?php

namespace App\Http\Controllers;

use App\Models\FinancialEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\TenantConnection;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    private function period(Request $r): array
    {
        abort_unless($r->attributes->get('currentUser')->role === 'admin', 403);
        $d = Validator::make(['from' => $r->query('from', now()->startOfMonth()->toDateString()), 'to' => $r->query('to', now()->toDateString())], ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();
        if (Carbon::parse($d['from'])->diffInDays(Carbon::parse($d['to'])) > 366) {
            throw ValidationException::withMessages(['to' => 'Escolha um período de até 366 dias.']);
        }

        return [$d['from'], $d['to']];
    }

    public function index(Request $r)
    {
        [$from,$to] = $this->period($r);
        $sales = Sale::where('status', 'completed')->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);
        $payments = Payment::whereBetween('paid_on', [$from, $to]);
        $top = DB::connection('tenant')->table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->where('sales.status', 'completed')->whereBetween('sales.created_at', [$from.' 00:00:00', $to.' 23:59:59'])->selectRaw('sale_items.description, SUM(sale_items.quantity) as quantity, SUM(sale_items.total) as total')->groupBy('sale_items.description')->orderByDesc('total')->limit(10)->get();

        return view('reports', ['from' => $from, 'to' => $to, 'revenue' => (clone $sales)->sum('total'), 'cost' => (clone $sales)->sum('cost_total'), 'saleCount' => (clone $sales)->count(), 'cashIn' => (clone $payments)->where('direction', 'in')->sum('amount'), 'cashOut' => (clone $payments)->where('direction', 'out')->sum('amount'), 'top' => $top, 'stockValue' => Product::selectRaw('COALESCE(SUM(quantity*average_cost),0) as total')->value('total')]);
    }

    public function csv(Request $r, string $company, string $kind)
    {
        $tenant = $r->attributes->get('tenant');
        [$from,$to] = $this->period($r);
        abort_unless(in_array($kind, ['stock', 'movements', 'sales', 'payments', 'entries']), 404);

        return response()->streamDownload(function () use ($kind, $from, $to, $tenant) {
            $connection = app(TenantConnection::class);
            $connection->connect($tenant);
            try {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                $write = function (array $row) use ($out) {
                    $safe = array_map(function ($v) {
                        $v = (string) ($v ?? '');

                        return preg_match('/^[\s]*[=+\-@]/u', $v) || preg_match('/^[\t\r\n]/', $v) ? "'".$v : $v;
                    }, $row);
                    fputcsv($out, $safe, ';', '"', '');
                };
                if ($kind === 'stock') {
                    $write(['SKU', 'Produto', 'Grupo', 'Unidade', 'Saldo atual', 'Custo médio atual', 'Valor atual do estoque', 'Preço venda', 'Margem alvo']);
                    foreach (Product::with('group')->orderBy('id')->cursor() as $p) {
                        $write([$p->sku, $p->name, $p->group->name, $p->unit, $p->quantity, $p->average_cost, bcmul($p->quantity, $p->average_cost, 4), $p->sale_price, $p->margin ?? $p->group->margin]);
                    }
                } elseif ($kind === 'movements') {
                    $write(['Data', 'Produto', 'Tipo', 'Quantidade', 'Custo unitário', 'Saldo após', 'Custo médio após', 'Motivo', 'Usuário']);
                    foreach (StockMovement::with('product')->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->orderBy('id')->cursor() as $m) {
                        $write([$m->created_at, $m->product->name, $m->type, $m->quantity, $m->unit_cost, $m->balance, $m->average_cost, $m->reason, $m->user_id]);
                    }
                } elseif ($kind === 'sales') {
                    $write(['Venda', 'Data venda', 'Cliente', 'Situação', 'Total', 'Custo histórico', 'Quitada']);
                    foreach (Sale::with('customer')->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->orderBy('id')->cursor() as $s) {
                        $write([$s->id, $s->created_at, $s->customer?->name, $s->status, $s->total, $s->cost_total, $s->paid ? 'Sim' : 'Não']);
                    }
                } elseif ($kind === 'payments') {
                    $write(['Baixa', 'Data financeira', 'Título', 'Tipo', 'Direção', 'Valor', 'Método', 'Usuário', 'Reversão de']);
                    foreach (Payment::whereBetween('paid_on', [$from, $to])->orderBy('id')->cursor() as $p) {
                        $write([$p->id, $p->paid_on, $p->entry_id, $p->kind, $p->direction, $p->amount, $p->method, $p->user_id, $p->reversal_of]);
                    }
                } else {
                    $write(['Título', 'Descrição', 'Direção', 'Parcela', 'Vencimento', 'Valor original', 'Valor liquidado', 'Saldo', 'Cancelado em']);
                    foreach (FinancialEntry::whereBetween('due_date', [$from, $to])->orderBy('id')->cursor() as $e) {
                        $write([$e->id, $e->description, $e->direction, $e->installment_no.'/'.$e->installments_count, $e->due_date, $e->amount, $e->paid_amount, $e->cancelled_at ? '0.00' : bcsub($e->amount, $e->paid_amount, 2), $e->cancelled_at]);
                    }
                }
                fclose($out);
            } finally {
                $connection->disconnect();
            }
        }, 'vendra-'.$kind.'-'.$from.'-'.$to.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
