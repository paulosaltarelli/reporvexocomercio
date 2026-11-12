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
use App\Models\Service;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class DemoComputerStore
{
    private function key(string $name): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:computer-store-demo:v1:'.$name)->toString();
    }

    public function seed(int $userId): array
    {
        return DB::connection('tenant')->transaction(function () use ($userId) {
            User::findOrFail($userId);
            $existing = DemoRun::where('code', 'computer-store-v1')->lockForUpdate()->first();
            if ($existing?->completed_at) {
                return $existing->summary;
            }
            if (Sale::exists() || Purchase::exists() || Payment::exists() || FinancialEntry::exists() || CashClosure::exists()) {
                throw ValidationException::withMessages(['demo' => 'A empresa já tem movimentos operacionais. Use uma empresa de demonstração separada para não misturar históricos.']);
            }
            if (Product::where('sku', 'like', 'DEMO-%')->exists()) {
                throw ValidationException::withMessages(['demo' => 'Já existem SKUs DEMO sem uma carga concluída. Nenhum cadastro foi alterado.']);
            }
            $anchor = CarbonImmutable::today();
            $run = $existing ?? DemoRun::create(['code' => 'computer-store-v1', 'anchor_date' => $anchor->toDateString(), 'user_id' => $userId]);
            $anchor = CarbonImmutable::parse($run->anchor_date)->startOfDay();
            $date = fn (int $offset) => $anchor->addDays($offset)->toDateString();
            $groups = [];
            foreach (['Computadores' => '25', 'Periféricos' => '40', 'Armazenamento' => '35', 'Redes' => '45', 'Impressão' => '35', 'Acessórios' => '50'] as $name => $margin) {
                $groups[$name] = ProductGroup::firstOrCreate(['name' => $name.' (Demo)'], ['margin' => $margin]);
            }
            $spec = [
                ['NB-I5', 'Notebook i5 · 8 GB · SSD 256 GB', 'Computadores', '2450.0000', '3299.00', 8],
                ['PC-I3', 'Desktop i3 · 8 GB · SSD 240 GB', 'Computadores', '1750.0000', '2499.00', 8],
                ['MON-24', 'Monitor LED 24 polegadas', 'Periféricos', '610.0000', '899.00', 12],
                ['MOUSE', 'Mouse óptico USB', 'Periféricos', '22.5000', '49.00', 50],
                ['TECLADO', 'Teclado USB ABNT2', 'Periféricos', '44.0000', '89.00', 35],
                ['HEADSET', 'Headset com microfone', 'Periféricos', '98.0000', '199.00', 20],
                ['SSD240', 'SSD SATA 240 GB', 'Armazenamento', '95.0000', '249.00', 25],
                ['SSD480', 'SSD SATA 480 GB', 'Armazenamento', '155.0000', '449.00', 20],
                ['RAM8', 'Memória DDR4 8 GB', 'Armazenamento', '85.0000', '189.00', 25],
                ['ROUTER', 'Roteador Wi-Fi dual band', 'Redes', '112.0000', '239.00', 15],
                ['CABO', 'Cabo USB-C 1 metro', 'Acessórios', '12.0000', '29.00', 60],
                ['TONER', 'Toner compatível preto', 'Impressão', '69.0000', '159.00', 15],
                ['PEN64', 'Pen drive 64 GB', 'Armazenamento', '19.0000', '59.00', 30],
                ['WEBCAM', 'Webcam Full HD USB', 'Periféricos', '78.0000', '169.00', 15],
                ['WIFI', 'Adaptador Wi-Fi USB', 'Redes', '28.0000', '69.00', 25],
            ];
            $products = [];
            $costs = [];
            $prices = [];
            foreach ($spec as [$sku,$name,$group,$cost,$price,$qty]) {
                $products[$sku] = Product::create(['sku' => 'DEMO-'.$sku, 'name' => $name.' (Demo)', 'product_group_id' => $groups[$group]->id, 'unit' => 'UN', 'sale_price' => $price, 'minimum_stock' => $sku === 'HEADSET' ? 18 : (in_array($sku, ['MOUSE', 'CABO', 'PEN64']) ? 10 : 3), 'active' => true]);
                $costs[$sku] = $cost;
                $prices[$sku] = $price;
            }
            $serviceGroup=ProductGroup::firstOrCreate(['name'=>'Serviços (Demo)'],['margin'=>'40.00','price_policy'=>'alert']);
            $services = [];
            $servicePrices = [];
            foreach ([['FORMAT', 'Formatação e configuração', '150.00', '45.00'], ['CLEAN', 'Limpeza preventiva de computador', '120.00', '25.00'], ['INSTALL', 'Instalação de sistema e aplicativos', '120.00', '35.00'], ['NETWORK', 'Configuração de rede e Wi-Fi', '180.00', '50.00'], ['CLONE', 'Clonagem de disco e migração para SSD', '120.00', '30.00'], ['SUPPORT', 'Atendimento técnico e diagnóstico', '90.00', '20.00']] as [$code,$name,$price,$cost]) {
                $services[$code] = Product::firstOrCreate(['sku'=>'DEMO-SERV-'.$code], ['name'=>$name.' (Demo)','product_group_id'=>$serviceGroup->id,'type'=>'service','controls_stock'=>false,'reference_cost'=>$cost,'sale_price'=>$price,'unit'=>'UN','active'=>true,'price_policy'=>'alert']);
                $servicePrices[$code] = $price;
            }
            $customers = [];
            foreach (['Ana · Cliente Demo', 'Bruno · Cliente Demo', 'Carla · Cliente Demo', 'Escritório Alfa (Demo)', 'Oficina Horizonte (Demo)', 'Loja Central (Demo)', 'Daniel · Cliente Demo', 'Escola Conecta (Demo)'] as $i => $name) {
                $customers[] = Customer::firstOrCreate(['name' => $name], ['email' => 'cliente'.($i + 1).'@example.test', 'address' => 'Cadastro fictício para demonstração.']);
            }
            $suppliers = [];
            foreach (['Distribuidora Byte (Demo)', 'Atacado Conecta (Demo)', 'Suprimentos Digital (Demo)'] as $i => $name) {
                $suppliers[] = Supplier::firstOrCreate(['name' => $name], ['email' => 'fornecedor'.($i + 1).'@example.test', 'address' => 'Fornecedor fictício para demonstração.']);
            }
            $finance = app(FinancialService::class);
            $checkout = app(Checkout::class);
            $makePurchase = function (string $code, int $offset, int $supplier, array $items, int $count, int $due, ?string $payMethod = null) use ($date, $userId, $products, $suppliers, $finance) {
                $lines = [];
                foreach ($items as [$sku,$qty,$cost]) {
                    $lines[] = ['product_id' => $products[$sku]->id, 'quantity' => (string) $qty, 'unit_cost' => $cost];
                }
                $p = app(PurchaseService::class)->receive(['request_key' => $this->key('purchase-'.$code), 'supplier_id' => $suppliers[$supplier]->id, 'reference' => 'DEMO-'.$code, 'purchased_on' => $date($offset), 'notes' => 'DEMONSTRAÇÃO — recebimento fictício; custo efetivo por unidade.', 'items' => $lines, 'paid' => false, 'installments' => $count, 'due_date' => $date($due), 'payment_method' => $payMethod ?? 'invoice'], $userId);
                $p->update(['created_at' => $date($offset).' 08:00:00', 'updated_at' => $date($offset).' 08:00:00']);
                StockMovement::where('request_key', 'like', 'purchase-'.$p->id.'-%')->update(['created_at' => $date($offset).' 08:00:00', 'updated_at' => $date($offset).' 08:00:00']);
                if ($payMethod) {
                    foreach ($p->entries()->get() as $e) {
                        $finance->pay($e->id, $e->amount, $date($offset), $payMethod, $userId, $this->key('purchase-pay-'.$code.'-'.$e->installment_no));
                    }
                }

                return $p;
            };
            $initial = [];
            foreach ($spec as [$sku,$name,$group,$cost,$price,$qty]) {
                $initial[] = [$sku, $qty, $cost];
            }
            $first = $makePurchase('COMPRA-001', -10, 0, $initial, 3, 5);
            // Events are processed in chronological stock order; quantities/cost snapshots remain coherent.
            $scenarios = [
                [-9, 'cash', 1, 0, [['p', 'MOUSE', 2], ['p', 'TECLADO', 1]], 'full'],
                [-8, 'pix', 1, 1, [['p', 'NB-I5', 1], ['p', 'SSD480', 1], ['s', 'INSTALL', 1]], 'full'],
                [-7, 'cash', 1, 2, [['p', 'SSD240', 1], ['s', 'FORMAT', 1]], 'full'],
                [-6, 'credit', 3, 3, [['p', 'PC-I3', 1], ['p', 'MON-24', 1], ['p', 'TECLADO', 1], ['p', 'MOUSE', 1]], 'partial600'],
                [-5, 'pix', 1, 4, [['p', 'ROUTER', 1], ['s', 'NETWORK', 1]], 'full'],
                [-4, 'cash', 1, 5, [['p', 'TONER', 2], ['p', 'CABO', 1]], 'full'],
                [-3, 'invoice', 4, 3, [['p', 'PC-I3', 2], ['p', 'MON-24', 2], ['s', 'NETWORK', 2]], 'pending'],
                [-3, 'pix', 1, 6, [['p', 'NB-I5', 1], ['p', 'RAM8', 1], ['s', 'FORMAT', 1]], 'full'],
                [-2, 'cash', 1, 0, [['p', 'PEN64', 3], ['p', 'MOUSE', 2]], 'full'],
                [-1, 'pix', 1, 1, [['p', 'HEADSET', 1], ['p', 'WEBCAM', 1]], 'full'],
                [-1, 'credit', 6, 7, [['p', 'NB-I5', 1], ['p', 'CABO', 1]], 'pending'],
                [-1, 'pix', 1, 2, [['p', 'ROUTER', 1], ['s', 'NETWORK', 1]], 'cancel'],
                [0, 'pix', 1, 6, [['p', 'SSD480', 1], ['s', 'CLONE', 1]], 'full'],
                [0, 'cash', 1, 4, [['s', 'CLEAN', 1], ['s', 'SUPPORT', 1]], 'full'],
                [0, 'invoice', 3, 5, [['p', 'SSD480', 1], ['p', 'RAM8', 1], ['s', 'INSTALL', 1]], 'partial100'],
                [0, 'pix', 1, 0, [['p', 'MOUSE', 1], ['p', 'HEADSET', 1]], 'full'],
            ];
            $second = false;
            $third = false;
            foreach ($scenarios as $i => [$offset,$method,$parts,$customer,$lines,$state]) {
                if (! $second && $offset >= -5) {
                    $makePurchase('COMPRA-002', -5, 1, [['NB-I5', 3, '2520.0000'], ['SSD480', 10, '162.0000'], ['MOUSE', 15, '23.0000']], 2, 12);
                    $second = true;
                }
                if (! $third && $offset >= -2) {
                    $makePurchase('COMPRA-003', -2, 2, [['CABO', 20, '13.0000'], ['TONER', 8, '72.0000'], ['PEN64', 20, '21.0000']], 1, -2, 'cash');
                    $third = true;
                }
                $items = [];
                foreach ($lines as [$kind,$code,$qty]) {
                    $items[] = ['kind' => 'product', 'id' => $kind === 'p' ? $products[$code]->id : $services[$code]->id, 'quantity' => (string) $qty, 'unit_price' => $kind === 'p' ? $prices[$code] : $servicePrices[$code]];
                }
                $due = in_array($state, ['pending', 'partial100']) ? ($offset === 0 ? 10 : 7) : $offset;
                $sale = $checkout->complete(['request_key' => $this->key('sale-'.$i), 'customer_id' => $customers[$customer]->id, 'payment_method' => $method, 'paid' => false, 'installments' => $parts, 'due_date' => $date($due), 'items' => $items, 'notes' => 'DEMONSTRAÇÃO — venda fictícia de loja de informática.'], $userId);
                $moment = CarbonImmutable::parse($date($offset).' '.str_pad((string) (10 + ($i % 7)), 2, '0', STR_PAD_LEFT).':15:00');
                if ($moment->isFuture()) {
                    $moment = CarbonImmutable::now()->subMinutes(count($scenarios) - $i);
                }if ($moment->lessThan($anchor)) {
                    $moment = $anchor;
                }$time = $moment->format('Y-m-d H:i:s');
                $sale->update(['created_at' => $time, 'updated_at' => $time]);
                StockMovement::where('sale_id', $sale->id)->where('type', 'sale')->update(['created_at' => $time, 'updated_at' => $time]);
                if (in_array($state, ['full', 'cancel'])) {
                    foreach ($sale->entries()->get() as $e) {
                        $finance->pay($e->id, $e->amount, $date($offset), $method, $userId, $this->key('sale-pay-'.$i.'-'.$e->installment_no));
                    }
                }
                if ($state === 'partial600') {
                    $e = $sale->entries()->orderBy('installment_no')->first();
                    $finance->pay($e->id, '600.00', $date(-4), 'credit', $userId, $this->key('sale-partial-'.$i));
                }
                if ($state === 'partial100') {
                    $e = $sale->entries()->orderBy('installment_no')->first();
                    $finance->pay($e->id, '100.00', $date(0), 'pix', $userId, $this->key('sale-partial-'.$i));
                }
                if ($state === 'cancel') {
                    $checkout->cancel($sale->id, $userId, true);
                }
            }
            $firstEntry = $first->entries()->orderBy('installment_no')->first();
            $finance->pay($firstEntry->id, '2500.00', $date(-4), 'transfer', $userId, $this->key('initial-purchase-partial'));
            foreach ([
                ['internet', 'out', 'Internet da loja (Demo)', 'Estrutura', '149.90', -7, 'pix'],
                ['rent', 'out', 'Aluguel da loja (Demo)', 'Estrutura', '1800.00', -5, 'transfer'],
                ['power', 'out', 'Energia elétrica (Demo)', 'Estrutura', '380.45', 4, null],
                ['accountant', 'out', 'Contabilidade (Demo)', 'Serviços', '450.00', 10, null],
                ['cleaning', 'out', 'Materiais de limpeza (Demo)', 'Consumo', '90.00', -2, 'cash'],
                ['marketing', 'out', 'Campanha de divulgação (Demo)', 'Marketing', '300.00', 8, null],
                ['maintenance', 'out', 'Manutenção da loja (Demo)', 'Manutenção', '120.00', -1, 'cash'],
                ['reimbursement', 'in', 'Reembolso de fornecedor (Demo)', 'Outras entradas', '60.00', -1, 'transfer'],
            ] as [$code,$direction,$description,$category,$amount,$offset,$method]) {
                $entries = $finance->schedule(['request_key' => $this->key('manual-'.$code), 'direction' => $direction, 'description' => $description, 'category' => $category, 'amount' => $amount, 'due_date' => $date($offset), 'installments' => 1], $userId);
                if ($method) {
                    foreach ($entries as $e) {
                        $finance->pay($e->id, $e->amount, $date($offset), $method, $userId, $this->key('manual-pay-'.$code));
                    }
                }
            }
            $docs = app(Documents::class);
            foreach ([['quote', 'draft', 0, 'Upgrade de computador do cliente (Demo)'], ['quote', 'approved', 3, 'Renovação de computadores do escritório (Demo)'], ['work_order', 'in_progress', 1, 'Notebook com lentidão · diagnóstico em execução (Demo)'], ['work_order', 'completed', 2, 'Computador revisado · aguardando venda (Demo)']] as $i => [$type,$status,$customer,$note]) {
                $d = $docs->create(['type' => $type, 'request_key' => $this->key('document-'.$i), 'customer_id' => $customers[$customer]->id, 'valid_until' => $date(7 + $i), 'asset' => $type === 'work_order' ? 'Equipamento fictício DEMO-OS-'.($i + 1) : null, 'technician' => $type === 'work_order' ? 'Técnico Demonstração' : null, 'notes' => $note, 'items' => [['kind' => 'product', 'id' => $products['SSD480']->id, 'quantity' => '1', 'unit_price' => '449.00'], ['kind' => 'product', 'id' => $services['CLONE']->id, 'quantity' => '1', 'unit_price' => '120.00']]], $userId);
                if ($status !== 'draft') {
                    $docs->transition($d->id, 'approved');
                }if (in_array($status, ['in_progress', 'completed'])) {
                    $docs->transition($d->id, 'in_progress');
                }if ($status === 'completed') {
                    $docs->transition($d->id, 'completed');
                }
            }
            // Close historical cash only AFTER every demonstration payment has been recorded.
            $opening = '500.00';
            for ($offset = -9; $offset <= -1; $offset++) {
                $summary = app(CashService::class)->summary($date($offset), $opening);
                $counted = $summary['expected_balance'];
                if ($offset === -4) {
                    $counted = bcsub($counted, '10.00', 2);
                }
                $close = app(CashService::class)->close(['request_key' => $this->key('cash-close-'.$offset), 'date' => $date($offset), 'opening_balance' => $opening, 'counted_balance' => $counted, 'notes' => $offset === -4 ? 'DEMO: diferença de R$ 10 identificada na contagem.' : 'DEMO: fechamento fictício para conhecer a rotina.'], $userId);
                $opening = (string) $close->counted_balance;
            }
            $summary = ['products' => count($products), 'groups' => count($groups), 'services' => count($services), 'customers' => count($customers), 'suppliers' => count($suppliers), 'purchases' => 3, 'sales' => count($scenarios), 'documents' => 4, 'cash_closures' => 9, 'anchor_date' => $anchor->toDateString()];
            $run->update(['completed_at' => now(), 'summary' => $summary]);

            return $summary;
        }, 3);
    }
}
