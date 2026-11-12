<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Cosmetic, bounded migration of the original computer-store fixture; never a reset. */
class ComputerStoreLabels
{
    private const CODE = 'computer-store-v1';

    private const PRODUCTS = [
        'NB-I5' => 'Notebook i5 · 8 GB · SSD 256 GB',
        'PC-I3' => 'Desktop i3 · 8 GB · SSD 240 GB',
        'MON-24' => 'Monitor LED 24 polegadas',
        'MOUSE' => 'Mouse óptico USB',
        'TECLADO' => 'Teclado USB ABNT2',
        'HEADSET' => 'Headset com microfone',
        'SSD240' => 'SSD SATA 240 GB',
        'SSD480' => 'SSD SATA 480 GB',
        'RAM8' => 'Memória DDR4 8 GB',
        'ROUTER' => 'Roteador Wi-Fi dual band',
        'CABO' => 'Cabo USB-C 1 metro',
        'TONER' => 'Toner compatível preto',
        'PEN64' => 'Pen drive 64 GB',
        'WEBCAM' => 'Webcam Full HD USB',
        'WIFI' => 'Adaptador Wi-Fi USB',
    ];

    private const SERVICES = [
        'FORMAT' => ['Formatação e configuração', '150.00', '45.00'],
        'CLEAN' => ['Limpeza preventiva de computador', '120.00', '25.00'],
        'INSTALL' => ['Instalação de sistema e aplicativos', '120.00', '35.00'],
        'NETWORK' => ['Configuração de rede e Wi-Fi', '180.00', '50.00'],
        'CLONE' => ['Clonagem de disco e migração para SSD', '120.00', '30.00'],
        'SUPPORT' => ['Atendimento técnico e diagnóstico', '90.00', '20.00'],
    ];

    private const GROUPS = ['Computadores', 'Periféricos', 'Armazenamento', 'Redes', 'Impressão', 'Acessórios', 'Serviços'];

    /** No caller-supplied company, IDs, keys or replacement text are accepted. */
    public function normalize(): array
    {
        $db = DB::connection('tenant');

        return $db->transaction(function () use ($db) {
            $result = ['eligible' => false, 'code' => self::CODE, 'updated' => 0, 'counts' => [], 'skipped' => []];
            $run = $db->table('demo_runs')->where('code', self::CODE)->lockForUpdate()->first();
            if (! $run || ! $run->completed_at) {
                return $result;
            }
            $result['eligible'] = true;
            $descriptions = [];
            $cohortProductIds = [];
            $cohortServiceIds = [];

            foreach (self::PRODUCTS as $code => $name) {
                $old = $name.' (Demo)';
                $descriptions[$old] = $name;
                $rows = $db->table('products')->whereRaw('BINARY sku = ?', ['DEMO-'.$code])->lockForUpdate()->get();
                if ($rows->count() !== 1) {
                    continue;
                }
                $row = $rows->first();
                $cohortProductIds[] = (int) $row->id;
                $changes = ['sku' => $this->available($db, 'products', 'sku', 'INF-'.$code, $row->id)];
                if ($row->name === $old) {
                    $changes['name'] = $name;
                }
                $this->patch($db, 'products', $row, $changes, $result);
            }

            foreach (self::SERVICES as $code => [$name, $price, $cost]) {
                $old = $name.' (Demo)';
                $descriptions[$old] = $name;
                foreach ($db->table('products')->whereRaw('BINARY sku = ?', ['DEMO-SERV-'.$code])->lockForUpdate()->get() as $row) {
                    $cohortProductIds[] = (int) $row->id;
                    $changes = ['sku' => $this->available($db, 'products', 'sku', 'SERV-'.$code, $row->id)];
                    if ($row->name === $old) {
                        $changes['name'] = $name;
                    }
                    $this->patch($db, 'products', $row, $changes, $result);
                }
                // Earlier revisions stored services in the archived services table.
                $legacy = $db->table('services')->whereRaw('BINARY name = ?', [$old])->where('price', $price)->where('cost', $cost)->lockForUpdate()->limit(2)->get();
                if ($legacy->count() > 1) {
                    $result['skipped'][] = 'services:'.$code.':ambiguous';
                    continue;
                }
                if ($legacy->count() === 1) {
                    $service = $legacy->first();
                    $cohortServiceIds[] = (int) $service->id;
                    $this->patch($db, 'services', $service, ['name' => $name], $result);
                    foreach ($db->table('products')->where('legacy_service_id', $service->id)->lockForUpdate()->get() as $row) {
                        $cohortProductIds[] = (int) $row->id;
                        $changes = [];
                        if ($row->name === $old) {
                            $changes['name'] = $name;
                        }
                        if (preg_match('/^LEGACY-SERVICE-'.preg_quote((string) $service->id, '/').'(?:-\d+)?$/D', $row->sku)) {
                            $changes['sku'] = $this->available($db, 'products', 'sku', 'SERV-'.$code, $row->id);
                        }
                        $this->patch($db, 'products', $row, $changes, $result);
                    }
                }
            }

            foreach (self::GROUPS as $name) {
                $row = $db->table('product_groups')->whereRaw('BINARY name = ?', [$name.' (Demo)'])->lockForUpdate()->first();
                if ($row) {
                    $this->patch($db, 'product_groups', $row, ['name' => $this->available($db, 'product_groups', 'name', $name, $row->id)], $result);
                }
            }

            $customers = ['Ana · Cliente Demo' => 'Ana', 'Bruno · Cliente Demo' => 'Bruno', 'Carla · Cliente Demo' => 'Carla', 'Escritório Alfa (Demo)' => 'Escritório Alfa', 'Oficina Horizonte (Demo)' => 'Oficina Horizonte', 'Loja Central (Demo)' => 'Loja Central', 'Daniel · Cliente Demo' => 'Daniel', 'Escola Conecta (Demo)' => 'Escola Conecta'];
            $number = 0;
            foreach ($customers as $old => $name) {
                $number++;
                $this->contact($db, 'customers', $old, $name, 'cliente'.$number.'@example.test', 'Cadastro fictício para demonstração.', $result);
            }
            $number = 0;
            foreach (['Distribuidora Byte', 'Atacado Conecta', 'Suprimentos Digital'] as $name) {
                $number++;
                $this->contact($db, 'suppliers', $name.' (Demo)', $name, 'fornecedor'.$number.'@example.test', 'Fornecedor fictício para demonstração.', $result);
            }

            $purchaseIds = [];
            foreach (['COMPRA-001', 'COMPRA-002', 'COMPRA-003'] as $code) {
                $row = $db->table('purchases')->where('request_key', $this->key('purchase-'.$code))->lockForUpdate()->first();
                if (! $row) {
                    continue;
                }
                $purchaseIds[] = $row->id;
                $changes = [];
                if ($row->reference === 'DEMO-'.$code) {
                    $changes['reference'] = $code;
                }
                if ($row->notes === 'DEMONSTRAÇÃO — recebimento fictício; custo efetivo por unidade.') {
                    $changes['notes'] = 'Custo efetivo informado por unidade.';
                }
                $this->patch($db, 'purchases', $row, $changes, $result);
            }

            $saleIds = [];
            for ($i = 0; $i < 16; $i++) {
                $row = $db->table('sales')->where('request_key', $this->key('sale-'.$i))->lockForUpdate()->first();
                if (! $row) {
                    continue;
                }
                $saleIds[] = $row->id;
                if ($row->notes === 'DEMONSTRAÇÃO — venda fictícia de loja de informática.') {
                    $this->patch($db, 'sales', $row, ['notes' => null], $result);
                }
            }
            // Recover the cohort after an earlier normalization too: original fixture
            // keys keep their item IDs and either the original or the clean descriptions.
            $knownDescriptions = array_merge(array_keys($descriptions), array_values($descriptions));
            if ($purchaseIds !== []) {
                foreach ($db->table('purchase_items')->whereIn('purchase_id', $purchaseIds)->lockForUpdate()->get() as $item) {
                    if (in_array($item->description, $knownDescriptions, true)) {
                        $cohortProductIds[] = (int) $item->product_id;
                    }
                }
            }
            if ($saleIds !== []) {
                foreach ($db->table('sale_items')->whereIn('sale_id', $saleIds)->lockForUpdate()->get() as $item) {
                    if (! in_array($item->description, $knownDescriptions, true)) {
                        continue;
                    }
                    if ($item->product_id !== null) {
                        $cohortProductIds[] = (int) $item->product_id;
                    }
                    if ($item->service_id !== null) {
                        $cohortServiceIds[] = (int) $item->service_id;
                    }
                }
            }
            $cohortServiceIds = array_values(array_unique($cohortServiceIds));
            if ($cohortServiceIds !== []) {
                foreach ($db->table('products')->whereIn('legacy_service_id', $cohortServiceIds)->pluck('id') as $id) {
                    $cohortProductIds[] = (int) $id;
                }
            }
            $cohortProductIds = array_values(array_unique($cohortProductIds));
            foreach ($descriptions as $old => $name) {
                if ($cohortProductIds !== [] || $cohortServiceIds !== []) {
                    // A real sale after the fixture can contain the same catalog item.
                    // Change its old exact label, never another ID or custom wording.
                    $count = $db->table('sale_items')->where(function ($query) use ($cohortProductIds, $cohortServiceIds) {
                        if ($cohortProductIds !== []) {
                            $query->whereIn('product_id', $cohortProductIds);
                        }
                        if ($cohortServiceIds !== []) {
                            $query->orWhereIn('service_id', $cohortServiceIds);
                        }
                    })->whereRaw('BINARY description = ?', [$old])->update(['description' => $name]);
                    $this->count($result, 'sale_items', $count);
                }
                if ($purchaseIds !== []) {
                    $count = $db->table('purchase_items')->whereIn('purchase_id', $purchaseIds)->whereRaw('BINARY description = ?', [$old])->update(['description' => $name]);
                    $this->count($result, 'purchase_items', $count);
                }
            }

            $notes = ['Upgrade de computador do cliente', 'Renovação de computadores do escritório', 'Notebook com lentidão · diagnóstico em execução', 'Computador revisado · aguardando venda'];
            foreach ($notes as $i => $note) {
                $row = $db->table('documents')->where('request_key', $this->key('document-'.$i))->lockForUpdate()->first();
                if (! $row) {
                    continue;
                }
                $changes = [];
                if ($row->notes === $note.' (Demo)') {
                    $changes['notes'] = $note;
                }
                if ($row->technician === 'Técnico Demonstração') {
                    $changes['technician'] = 'Equipe técnica';
                }
                if ($row->asset === 'Equipamento fictício DEMO-OS-'.($i + 1)) {
                    $changes['asset'] = 'Equipamento OS-'.($i + 1);
                }
                $items = json_decode($row->items, true, 512, JSON_THROW_ON_ERROR);
                $changedItems = false;
                foreach ($items as &$item) {
                    if (isset($item['description']) && isset($descriptions[$item['description']])) {
                        $item['description'] = $descriptions[$item['description']];
                        $changedItems = true;
                    }
                }
                unset($item);
                if ($changedItems) {
                    $changes['items'] = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
                $this->patch($db, 'documents', $row, $changes, $result);
            }

            if ($cohortProductIds !== [] || $cohortServiceIds !== []) {
                // Only candidate JSON snapshots with an old known label are read.
                // Notes/assets/status of documents outside the fixture are untouched.
                $candidates = $db->table('documents')->where(function ($query) use ($descriptions) {
                    foreach (array_keys($descriptions) as $old) {
                        $query->orWhereRaw('JSON_SEARCH(items, \'one\', ?, NULL, \'$[*].description\') IS NOT NULL', [$old]);
                    }
                })->lockForUpdate()->get();
                foreach ($candidates as $row) {
                    $items = json_decode($row->items, true, 512, JSON_THROW_ON_ERROR);
                    $changed = false;
                    foreach ($items as &$item) {
                        $kind = $item['kind'] ?? null;
                        $id = (int) ($item['id'] ?? 0);
                        $belongs = ($kind === 'product' && in_array($id, $cohortProductIds, true))
                            || ($kind === 'service' && in_array($id, $cohortServiceIds, true));
                        if ($belongs && isset($item['description'], $descriptions[$item['description']])) {
                            $item['description'] = $descriptions[$item['description']];
                            $changed = true;
                        }
                    }
                    unset($item);
                    if ($changed) {
                        $this->patch($db, 'documents', $row, ['items' => json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)], $result);
                    }
                }
            }

            $entries = ['internet' => 'Internet da loja', 'rent' => 'Aluguel da loja', 'power' => 'Energia elétrica', 'accountant' => 'Contabilidade', 'cleaning' => 'Materiais de limpeza', 'marketing' => 'Campanha de divulgação', 'maintenance' => 'Manutenção da loja', 'reimbursement' => 'Reembolso de fornecedor'];
            foreach ($entries as $code => $description) {
                $key = $this->key('manual-'.$code);
                $count = $db->table('financial_entries')->where(function ($query) use ($key) {
                    $query->where('schedule_key', $key)->orWhere('request_key', $key);
                })->whereRaw('BINARY description = ?', [$description.' (Demo)'])->update(['description' => $description]);
                $this->count($result, 'financial_entries', $count);
            }

            // DemoRun is deliberately preserved; CashClosure and its JSON snapshot are never updated.
            return $result;
        }, 3);
    }

    private function contact(Connection $db, string $table, string $old, string $name, string $email, string $oldAddress, array &$result): void
    {
        $rows = $db->table($table)->whereRaw('BINARY name = ?', [$old])->whereRaw('BINARY email = ?', [$email])->lockForUpdate()->limit(2)->get();
        if ($rows->count() !== 1) {
            if ($rows->count() > 1) {
                $result['skipped'][] = $table.':'.$email.':ambiguous';
            }
            return;
        }
        $row = $rows->first();
        $changes = ['name' => $name];
        if ($row->address === $oldAddress) {
            $changes['address'] = null;
        }
        $this->patch($db, $table, $row, $changes, $result);
    }

    /** Keep a colliding pre-existing identifier and its record completely untouched. */
    private function available(Connection $db, string $table, string $column, string $preferred, int $id): string
    {
        $candidate = $preferred;
        $suffix = 0;
        while ($db->table($table)->where($column, $candidate)->where('id', '<>', $id)->exists()) {
            $candidate = $preferred.'-'.$id.($suffix === 0 ? '' : '-'.$suffix);
            $suffix++;
        }
        return $candidate;
    }

    private function patch(Connection $db, string $table, object $row, array $changes, array &$result): void
    {
        $changes = array_filter($changes, fn ($value, $field) => $row->$field !== $value, ARRAY_FILTER_USE_BOTH);
        if ($changes !== []) {
            $this->count($result, $table, $db->table($table)->where('id', $row->id)->update($changes));
        }
    }

    private function count(array &$result, string $table, int $count): void
    {
        if ($count > 0) {
            $result['counts'][$table] = ($result['counts'][$table] ?? 0) + $count;
            $result['updated'] += $count;
        }
    }

    private function key(string $name): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:computer-store-demo:v1:'.$name)->toString();
    }
}
