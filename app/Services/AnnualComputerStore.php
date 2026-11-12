<?php

namespace App\Services;

use App\Models\CashClosure;
use App\Models\Customer;
use App\Models\DemoRun;
use App\Models\FinancialEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Explicit, console-only synthetic archive import; never used by operational payment endpoints. */
class AnnualComputerStore
{
    private CarbonImmutable $start;
    private CarbonImmutable $end;
    private int $year;
    private int $userId;
    private array $products = [];
    private array $services = [];
    private array $customers = [];
    private Supplier $supplier;
    private array $counts = ['sales' => 0, 'purchases' => 0, 'payments' => 0, 'entries' => 0, 'cash_closures' => 0, 'forecasts' => 0];

    private function key(string $name): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:annual-computer-history:v1:'.$this->year.':'.$name)->toString();
    }

    public function plan(int $year = 2026): array
    {
        $today = CarbonImmutable::today();
        if ($year < 2000 || $year > $today->year) {
            throw ValidationException::withMessages(['year' => 'Informe um ano histórico até o ano atual.']);
        }
        $first = CashClosure::orderBy('date')->first();
        if (! $first) {
            throw ValidationException::withMessages(['history' => 'A base precisa possuir um primeiro fechamento para ancorar a continuidade do histórico.']);
        }
        $start = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $end = $first->date->toImmutable()->subDay()->min($start->endOfYear())->min($today);
        $balanced = $end->toDateString() === $start->endOfYear()->toDateString();
        $sellingDays = 0;
        for ($month = $start; $month->lte($end); $month = $month->addMonth()) {
            $sellingDays += count($this->sellingDays($month, $end, $balanced));
        }

        return ['year' => $year, 'as_of' => $today->toDateString(), 'import_start' => $start->toDateString(),
            'import_end' => $end->toDateString(), 'first_existing_closure' => $first->date->toDateString(),
            'bridge_balance' => (string) $first->opening_balance, 'estimated_sales' => $sellingDays * 2,
            'balanced_monthly_volume' => $balanced,
            'estimated_cash_closures' => max(0, (int) $start->diffInDays($end, false) + 1),
            'existing_closures' => CashClosure::count(),
            'overlapping_payments' => Payment::whereBetween('paid_on', [$start->toDateString(), $end->toDateString()])->count()];
    }

    public function seed(int $userId, int $year = 2026): array
    {
        if (! app()->environment('testing') && ! app()->isDownForMaintenance()) {
            throw ValidationException::withMessages(['history' => 'Coloque somente este sistema em manutenção antes de importar o histórico anual.']);
        }
        if (! app()->runningInConsole()) {
            throw ValidationException::withMessages(['history' => 'A importação anual é exclusiva do console administrativo.']);
        }
        $this->year = $year;
        $this->userId = $userId;
        $this->counts = ['sales' => 0, 'purchases' => 0, 'payments' => 0, 'entries' => 0, 'cash_closures' => 0, 'forecasts' => 0];

        return DB::connection('tenant')->transaction(function () use ($userId, $year) {
            // Transaction retries must not retain identifiers from a rolled-back attempt.
            $this->products = [];
            $this->services = [];
            $this->customers = [];
            $this->counts = ['sales' => 0, 'purchases' => 0, 'payments' => 0, 'entries' => 0, 'cash_closures' => 0, 'forecasts' => 0];
            User::where('active', true)->where('role', 'admin')->findOrFail($userId);
            $base = DemoRun::where('code', 'computer-store-v1')->lockForUpdate()->first();
            if (! $base?->completed_at) {
                throw ValidationException::withMessages(['history' => 'A carga anual só é permitida na base de informática previamente preparada.']);
            }
            $runCode = 'annual-computer-history-v1-'.$year;
            $existing = DemoRun::where('code', $runCode)->lockForUpdate()->first();
            if ($existing?->completed_at) return $existing->summary;
            if ($existing) throw ValidationException::withMessages(['history' => 'Existe uma importação incompleta. Inspecione antes de retomar.']);
            DB::connection('tenant')->table('cash_closure_locks')->where('id', 1)->lockForUpdate()->firstOrFail();
            $plan = $this->plan($year);
            $this->start = CarbonImmutable::parse($plan['import_start']);
            $this->end = CarbonImmutable::parse($plan['import_end']);
            if ($this->end->lt($this->start)) throw ValidationException::withMessages(['history' => 'Não existe intervalo histórico anterior ao primeiro fechamento neste ano.']);
            if (Payment::whereBetween('paid_on', [$this->start->toDateString(), $this->end->toDateString()])->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['history' => 'Já existem movimentos no intervalo reservado ao histórico. Nenhum dado foi alterado.']);
            }
            if (Product::where('sku', 'like', 'VXH-'.$year.'-%')->exists()) {
                throw ValidationException::withMessages(['history' => 'Os códigos reservados ao histórico já existem sem uma carga concluída.']);
            }
            $originalClosures = CashClosure::orderBy('id')->lockForUpdate()->get();
            $originalDigest = hash('sha256', $originalClosures->toJson());
            $originalIds = $originalClosures->modelKeys();
            $run = DemoRun::create(['code' => $runCode, 'anchor_date' => $plan['as_of'], 'user_id' => $userId]);
            $this->catalog();
            $cashHeld = '0.00';
            $globalDay = 0;
            for ($month = $this->start->startOfMonth(); $month->lte($this->end); $month = $month->addMonth()) {
                $days = $this->sellingDays($month, $this->end, $plan['balanced_monthly_volume']);
                if ($plan['balanced_monthly_volume']) $globalDay = 0;
                if ($days === []) continue;
                $quantities = array_fill_keys(array_keys($this->products), 0);
                $daily = [];
                foreach ($days as $day) {
                    $a = [['code' => 'MOUSE', 'quantity' => 2]];
                    if ($globalDay % 2 === 0) $a[] = ['code' => 'KEYBOARD', 'quantity' => 1];
                    $b = [['code' => 'SSD', 'quantity' => 1]];
                    if ($globalDay % 3 === 0) $b[] = ['code' => 'MONITOR', 'quantity' => 1];
                    if ($globalDay % 10 === 0) $b[] = ['code' => 'DESKTOP', 'quantity' => 1];
                    foreach (array_merge($a, $b) as $line) $quantities[$line['code']] += $line['quantity'];
                    $daily[] = [$day, $globalDay++, $a, $b];
                }
                $this->purchase($month, $days[0], $quantities);
                foreach ($daily as [$day, $index, $a, $b]) {
                    $method = $index % 4 === 0 ? 'cash' : 'pix';
                    $sale = $this->sale($day, $index, 'A', $a, $index % 2 === 0 ? 'SETUP' : null, $method, 1);
                    if ($method === 'cash') $cashHeld = bcadd($cashHeld, $sale->total, 2);
                    $this->sale($day, $index, 'B', $b, $index % 3 === 0 ? 'NETWORK' : null, $index % 3 === 0 ? 'credit' : 'pix', $index % 3 === 0 ? 3 : 1);
                    if ($day->isFriday() || $day->equalTo(end($days))) {
                        $this->deposit($day, $cashHeld);
                        $cashHeld = '0.00';
                    }
                }
                $this->expenses($month);
            }
            if (bccomp($cashHeld, '0', 2) !== 0) throw new \LogicException('O histórico não reconciliou o numerário transferido.');
            $opening = $plan['bridge_balance'];
            for ($day = $this->start; $day->lte($this->end); $day = $day->addDay()) {
                $summary = app(CashService::class)->summary($day->toDateString(), $opening);
                if (bccomp($summary['expected_balance'], '0', 2) < 0) throw new \LogicException('O histórico produziria caixa físico negativo.');
                unset($summary['is_closed'], $summary['closure_id']);
                CashClosure::create(['request_key' => $this->key('close-'.$day->toDateString()), 'date' => $day->toDateString(),
                    'user_id' => $userId, 'opening_balance' => $opening, 'cash_in' => $summary['cash_in'], 'cash_out' => $summary['cash_out'],
                    'expected_balance' => $summary['expected_balance'], 'counted_balance' => $summary['expected_balance'],
                    'difference' => '0.00', 'snapshot' => $summary, 'notes' => 'Fechamento diário', 'closed_at' => now()]);
                $dayRow = DB::connection('tenant')->table('cash_days')->where('date', $day->toDateString())->lockForUpdate()->first();
                if ($dayRow?->closed_at !== null) throw new \LogicException('Há um dia consolidado fora da cadeia de fechamentos. Inspecione antes de importar.');
                DB::connection('tenant')->table('cash_days')->insertOrIgnore(['date' => $day->toDateString()]);
                DB::connection('tenant')->table('cash_days')->where('date', $day->toDateString())->update(['closed_at' => now()]);
                $opening = $summary['expected_balance'];
                $this->counts['cash_closures']++;
            }
            if (bccomp($opening, $plan['bridge_balance'], 2) !== 0) throw new \LogicException('O saldo de ligação não coincide com a abertura já existente.');
            foreach ($this->products as $product) {
                if (bccomp($product->fresh()->quantity, '0', 3) !== 0) throw new \LogicException('O estoque histórico não encerrou zerado.');
                $product->update(['active' => false]);
            }
            foreach ($this->services as $service) $service->update(['active' => false]);
            $this->forecasts(CarbonImmutable::parse($plan['as_of']));
            $preserved = CashClosure::whereIn('id', $originalIds)->orderBy('id')->get();
            if (! hash_equals($originalDigest, hash('sha256', $preserved->toJson()))) throw new \LogicException('Um fechamento anterior foi alterado. Importação revertida.');
            $summary = array_merge($plan, $this->counts, ['synthetic' => true, 'importer_version' => 1,
                'recorded_at' => now()->toIso8601String(), 'preserved_closures_sha256' => $originalDigest,
                'new_product_ids' => array_map(fn ($p) => $p->id, $this->products),
                'new_service_ids' => array_map(fn ($p) => $p->id, $this->services)]);
            $run->update(['completed_at' => now(), 'summary' => $summary]);

            return $summary;
        }, 3);
    }

    /** Complete historical years use 22 evenly spaced selling days per month. */
    private function sellingDays(CarbonImmutable $month, CarbonImmutable $end, bool $balanced): array
    {
        $candidates = [];
        $until = $month->endOfMonth()->min($end);
        for ($day = $month->startOfMonth(); $day->lte($until); $day = $day->addDay()) {
            if ($balanced ? ! $day->isSunday() : $day->isWeekday()) $candidates[] = $day;
        }
        if (! $balanced) return $candidates;
        if (count($candidates) < 22) throw new \LogicException('O mês completo não tem dias suficientes para a carga equilibrada.');
        $days = [];
        for ($i = 0; $i < 22; $i++) $days[] = $candidates[(int) floor($i * (count($candidates) - 1) / 21)];

        return $days;
    }

    private function catalog(): void
    {
        $group = ProductGroup::firstOrCreate(['name' => 'Equipamentos corporativos'], ['margin' => '35.00', 'price_policy' => 'alert']);
        foreach ([
            'MOUSE' => ['Mouse sem fio ergonômico', '29.0000', '69.00'],
            'KEYBOARD' => ['Teclado compacto sem fio', '58.0000', '129.00'],
            'SSD' => ['SSD NVMe 500 GB', '182.0000', '389.00'],
            'MONITOR' => ['Monitor empresarial 21,5 polegadas', '510.0000', '799.00'],
            'DESKTOP' => ['Computador compacto i5 corporativo', '1850.0000', '2790.00'],
        ] as $code => [$name, $cost, $price]) {
            $this->products[$code] = Product::create(['sku' => 'VXH-'.$this->year.'-'.$code, 'name' => $name,
                'product_group_id' => $group->id, 'type' => 'product', 'controls_stock' => true,
                'sale_price' => $price, 'minimum_stock' => '0.000', 'unit' => 'UN', 'active' => true, 'price_policy' => 'alert']);
        }
        foreach (['SETUP' => ['Implantação de estação de trabalho', '180.00', '45.0000'],
            'NETWORK' => ['Assistência preventiva em redes', '240.00', '60.0000']] as $code => [$name, $price, $cost]) {
            $this->services[$code] = Product::create(['sku' => 'VXH-'.$this->year.'-SERV-'.$code, 'name' => $name,
                'product_group_id' => $group->id, 'type' => 'service', 'controls_stock' => false,
                'reference_cost' => $cost, 'sale_price' => $price, 'unit' => 'UN', 'active' => true, 'price_policy' => 'alert']);
            app(PricingService::class)->syncProduct($this->services[$code]->id, $this->userId, 'annual:catalog');
        }
        foreach (['Marina Oliveira', 'Ricardo Nunes', 'Renata Costa', 'Clínica Horizonte', 'Ponte Gestão Empresarial', 'Mercado Vila Nova'] as $i => $name) {
            $this->customers[] = Customer::firstOrCreate(['email' => 'anual'.$this->year.'.cliente'.($i + 1).'@vexo.example'],
                ['name' => $name, 'address' => 'São Paulo · SP']);
        }
        $this->supplier = Supplier::firstOrCreate(['email' => 'anual'.$this->year.'.fornecedor@vexo.example'],
            ['name' => 'Atacado Vetor Tecnologia', 'address' => 'São Paulo · SP']);
    }

    private function purchase(CarbonImmutable $month, CarbonImmutable $day, array $quantities): void
    {
        $baseCosts = ['MOUSE' => '29.0000', 'KEYBOARD' => '58.0000', 'SSD' => '182.0000', 'MONITOR' => '510.0000', 'DESKTOP' => '1850.0000'];
        $costSteps = ['MOUSE' => '0.35', 'KEYBOARD' => '0.50', 'SSD' => '1.50', 'MONITOR' => '3.00', 'DESKTOP' => '8.00'];
        $basePrices = ['MOUSE' => '69.00', 'KEYBOARD' => '129.00', 'SSD' => '389.00', 'MONITOR' => '799.00', 'DESKTOP' => '2790.00'];
        $priceSteps = ['MOUSE' => '1.00', 'KEYBOARD' => '1.00', 'SSD' => '3.00', 'MONITOR' => '5.00', 'DESKTOP' => '15.00'];
        $items = [];
        foreach ($quantities as $code => $qty) {
            if ($qty === 0) continue;
            $cost = bcadd($baseCosts[$code], bcmul((string) $month->month, $costSteps[$code], 4), 4);
            $items[] = ['product_id' => $this->products[$code]->id, 'quantity' => (string) $qty, 'unit_cost' => $cost];
        }
        $purchase = app(PurchaseService::class)->receive([
            'request_key' => $this->key('purchase-'.$month->format('Y-m')), 'supplier_id' => $this->supplier->id,
            'reference' => 'H'.$this->year.'-'.$month->format('m'), 'purchased_on' => $day->toDateString(),
            'notes' => 'Aquisição de equipamentos e suprimentos', 'items' => $items,
            'paid' => false, 'installments' => 3, 'due_date' => $day->toDateString(), 'payment_method' => 'transfer',
        ], $this->userId);
        $time = $day->format('Y-m-d').' 08:00:00';
        DB::connection('tenant')->table('purchases')->where('id', $purchase->id)->update(['created_at' => $time, 'updated_at' => $time]);
        DB::connection('tenant')->table('purchase_items')->where('purchase_id', $purchase->id)->update(['created_at' => $time, 'updated_at' => $time]);
        DB::connection('tenant')->table('stock_movements')->where('request_key', 'like', 'purchase-'.$purchase->id.'-%')->update(['created_at' => $time, 'updated_at' => $time]);
        foreach ($this->products as $code => $product) {
            $oldPrice = (string) $product->fresh()->sale_price;
            $product->update(['sale_price' => bcadd($basePrices[$code], bcmul((string) $month->month, $priceSteps[$code], 2), 2)]);
            app(PricingService::class)->syncProduct($product->id, $this->userId, 'annual:price:'.$month->format('Y-m'), $oldPrice);
        }
        $this->counts['purchases']++;
        foreach ($purchase->entries()->orderBy('installment_no')->get() as $entry) {
            $this->counts['entries']++;
            $this->stampEntry($entry, $time);
            if ($entry->due_date->toImmutable()->lte($this->end)) {
                $this->historicalPayment($entry, (string) $entry->amount, $entry->due_date->toImmutable(), 'transfer', 'purchase-'.$purchase->request_key.'-'.$entry->installment_no);
            }
        }
    }

    private function sale(CarbonImmutable $day, int $index, string $series, array $lines, ?string $serviceCode, string $method, int $installments): Sale
    {
        $items = [];
        foreach ($lines as $line) {
            $product = $this->products[$line['code']]->fresh();
            $items[] = ['kind' => 'product', 'id' => $product->id, 'quantity' => (string) $line['quantity'], 'unit_price' => (string) $product->sale_price];
        }
        if ($serviceCode) {
            $service = $this->services[$serviceCode];
            $items[] = ['kind' => 'product', 'id' => $service->id, 'quantity' => '1', 'unit_price' => (string) $service->sale_price];
        }
        $sale = app(Checkout::class)->complete(['request_key' => $this->key('sale-'.$day->toDateString().'-'.$series),
            'customer_id' => $this->customers[$index % count($this->customers)]->id,
            'payment_method' => $method, 'paid' => false, 'installments' => $installments,
            'due_date' => $day->toDateString(), 'items' => $items, 'notes' => 'Venda de produtos e serviços'], $this->userId);
        $time = $day->toDateString().($series === 'A' ? ' 10:00:00' : ' 15:00:00');
        DB::connection('tenant')->table('sales')->where('id', $sale->id)->update(['created_at' => $time, 'updated_at' => $time]);
        DB::connection('tenant')->table('sale_items')->where('sale_id', $sale->id)->update(['created_at' => $time, 'updated_at' => $time]);
        DB::connection('tenant')->table('stock_movements')->where('sale_id', $sale->id)->update(['created_at' => $time, 'updated_at' => $time]);
        $this->counts['sales']++;
        foreach ($sale->entries()->orderBy('installment_no')->get() as $entry) {
            $this->counts['entries']++;
            $this->stampEntry($entry, $time);
            if ($entry->due_date->toImmutable()->lte($this->end)) {
                $this->historicalPayment($entry, (string) $entry->amount, $entry->due_date->toImmutable(), $method, 'sale-'.$sale->request_key.'-'.$entry->installment_no);
            }
        }

        return $sale->fresh();
    }

    private function expenses(CarbonImmutable $month): void
    {
        foreach ([['rent', 'Aluguel', 'Estrutura', '1800.00', 5], ['power', 'Energia elétrica', 'Estrutura', '395.00', 12],
            ['internet', 'Internet e telefonia', 'Estrutura', '169.90', 18], ['accounting', 'Assessoria contábil', 'Administrativo', '450.00', 22]] as [$code, $name, $category, $amount, $day]) {
            $date = $month->day($day);
            if ($date->gt($this->end)) continue;
            $entries = app(FinancialService::class)->schedule(['request_key' => $this->key('expense-'.$code.'-'.$month->format('Y-m')),
                'direction' => 'out', 'description' => $name.' · '.$month->format('m/Y'), 'category' => $category,
                'amount' => $amount, 'due_date' => $date->toDateString(), 'installments' => 1], $this->userId);
            foreach ($entries as $entry) {
                $this->counts['entries']++;
                $this->stampEntry($entry, $date->toDateString().' 16:00:00');
                $this->historicalPayment($entry, (string) $entry->amount, $date, 'transfer', 'expense-'.$entry->request_key);
            }
        }
    }

    private function deposit(CarbonImmutable $day, string $amount): void
    {
        if (bccomp($amount, '0', 2) === 0) return;
        foreach ([['out', 'cash', 'Sangria para depósito bancário'], ['in', 'transfer', 'Crédito de depósito do caixa']] as [$direction, $method, $description]) {
            $entries = app(FinancialService::class)->schedule([
                'request_key' => $this->key('deposit-'.$direction.'-'.$day->toDateString()),
                'direction' => $direction, 'description' => $description, 'category' => 'Transferência interna',
                'amount' => $amount, 'due_date' => $day->toDateString(), 'installments' => 1,
            ], $this->userId);
            foreach ($entries as $entry) {
                $this->counts['entries']++;
                $this->stampEntry($entry, $day->toDateString().' 17:00:00');
                $this->historicalPayment($entry, (string) $entry->amount, $day, $method, 'deposit-'.$direction.'-'.$day->toDateString());
            }
        }
    }

    /** Import-only path: exact archive interval, exclusive calendar lock and fully rolled-back batch. */
    private function historicalPayment(FinancialEntry $entry, string $amount, CarbonImmutable $date, string $method, string $code): void
    {
        if ($date->lt($this->start) || $date->gt($this->end) || DB::connection('tenant')->transactionLevel() === 0) {
            throw new \LogicException('A baixa histórica saiu do intervalo aprovado da importação.');
        }
        $amount = Decimal::input($amount, 2, 'amount', '0.01');
        $method = FinancialService::paymentMethod($method);
        $entry = FinancialEntry::lockForUpdate()->findOrFail($entry->id);
        $balance = bcsub($entry->amount, $entry->paid_amount, 2);
        if ($entry->cancelled_at || bccomp($amount, $balance, 2) > 0) throw new \LogicException('Saldo inválido na importação histórica.');
        Payment::create(['request_key' => $this->key('payment-'.$code), 'entry_id' => $entry->id, 'user_id' => $this->userId,
            'amount' => $amount, 'paid_on' => $date->toDateString(), 'method' => $method, 'direction' => $entry->direction,
            'kind' => 'payment', 'created_at' => $date->toDateString().' 17:00:00', 'updated_at' => $date->toDateString().' 17:00:00']);
        $paid = bcadd($entry->paid_amount, $amount, 2);
        $entry->update(['paid_amount' => $paid, 'paid_at' => bccomp($paid, $entry->amount, 2) === 0 ? $date->toDateString().' 17:00:00' : null]);
        $parent = $entry->sale_id ? Sale::findOrFail($entry->sale_id) : ($entry->purchase_id ? Purchase::findOrFail($entry->purchase_id) : null);
        if ($parent) {
            $entries = $parent->entries()->lockForUpdate()->get();
            $settled = $entries->isNotEmpty();
            foreach ($entries as $part) $settled = $settled && bccomp($part->amount, $part->paid_amount, 2) === 0;
            $parent->update(['paid' => $settled]);
        }
        $this->counts['payments']++;
    }

    private function stampEntry(FinancialEntry $entry, string $time): void
    {
        DB::connection('tenant')->table('financial_entries')->where('id', $entry->id)->update(['created_at' => $time, 'updated_at' => $time]);
    }

    private function forecasts(CarbonImmutable $asOf): void
    {
        for ($month = $asOf->startOfMonth(); $month->year === $this->year; $month = $month->addMonth()) {
            $due = $month->endOfMonth();
            if ($due->toDateString() <= $asOf->toDateString()) continue;
            $remaining = $month->lt($asOf) ? (int) $asOf->diffInDays($due) : $due->day;
            foreach ([['in', 'Recebimentos previstos', $month->month === 11 ? '80000.00' : '65000.00'],
                ['out', 'Despesas previstas', $month->month === 11 ? '48000.00' : '39000.00']] as [$direction, $description, $fullAmount]) {
                $amount = Decimal::round(bcdiv(bcmul($fullAmount, (string) $remaining, 4), (string) $due->day, 4));
                if (bccomp($amount, '0', 2) <= 0) continue;
                $entries = app(FinancialService::class)->schedule(['request_key' => $this->key('forecast-'.$direction.'-'.$month->format('Y-m')),
                    'direction' => $direction, 'description' => $description.' · '.$month->format('m/Y'), 'category' => 'Previsões de caixa',
                    'amount' => $amount, 'due_date' => $due->toDateString(), 'installments' => 1], $this->userId);
                $this->counts['entries'] += $entries->count();
                $this->counts['forecasts'] += $entries->count();
            }
        }
    }
}
