<?php

namespace App\Http\Controllers;

use App\Fiscal\FiscalProfileManager;
use App\Models\FiscalItemRule;
use App\Models\FiscalTaxProfile;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Http\Request;

class FiscalProfileController extends Controller
{
    private function context(Request $r, FiscalProfileManager $manager): array
    {
        $tenant = $r->attributes->get('tenant');
        $userId = (int) $r->attributes->get('currentUser')->id;
        $manager->authorize($tenant, $userId);
        return [$tenant, $userId];
    }

    public function index(Request $r, string $company, FiscalProfileManager $manager)
    {
        $this->context($r, $manager);
        $profiles = FiscalTaxProfile::withCount('products', 'groups')->orderBy('name')->get();
        $groups = ProductGroup::orderBy('name')->get();
        $groupHashes = $groups->mapWithKeys(fn ($g) => [$g->id => $manager->groupBindingHash($g)])->all();
        $groupImpactCounts = Product::where('type', 'product')->whereNull('fiscal_tax_profile_id')->whereNotIn('id', FiscalItemRule::select('product_id'))->selectRaw('product_group_id, COUNT(*) AS aggregate')->groupBy('product_group_id')->pluck('aggregate', 'product_group_id')->all();
        return view('fiscal.profiles.index', compact('profiles', 'groups', 'groupHashes', 'groupImpactCounts'));
    }

    public function create(Request $r, string $company, FiscalProfileManager $manager)
    {
        $this->context($r, $manager);
        return view('fiscal.profiles.form', ['profile' => null]);
    }

    public function edit(Request $r, string $company, int $id, FiscalProfileManager $manager)
    {
        $this->context($r, $manager);
        return view('fiscal.profiles.form', ['profile' => FiscalTaxProfile::findOrFail($id)]);
    }

    public function store(Request $r, string $company, FiscalProfileManager $manager)
    {
        return $this->save($r, $company, $manager);
    }

    public function update(Request $r, string $company, int $id, FiscalProfileManager $manager)
    {
        return $this->save($r, $company, $manager, $id);
    }

    private function save(Request $r, string $company, FiscalProfileManager $manager, ?int $id = null)
    {
        [$tenant, $userId] = $this->context($r, $manager);
        $data = $r->validate(['name' => 'required|string|max:120', 'active' => 'required|boolean', 'accountant_reference' => 'required|string|min:3|max:180', 'revision' => $id ? 'required|integer|min:1' : 'nullable|integer', 'tenant_id' => 'prohibited']);
        $data['parameters'] = $r->all();
        $manager->save($tenant, $userId, $data, $id, $id ? (int) $data['revision'] : null);
        return redirect()->route('fiscal.profiles.index', ['company' => $company])->with('success', 'Perfil tributário salvo. A alteração afeta novas conferências e emissões; documentos congelados foram preservados.');
    }

    public function bulk(Request $r, string $company, int $id, FiscalProfileManager $manager)
    {
        $this->context($r, $manager);
        return $this->bulkView($id);
    }

    public function preview(Request $r, string $company, int $id, FiscalProfileManager $manager)
    {
        [$tenant, $userId] = $this->context($r, $manager);
        $data = $this->bulkData($r);
        $preview = $manager->preview($tenant, $userId, $id, $data['product_ids'], $r->boolean('replace_existing'));
        return $this->bulkView($id, $preview);
    }

    public function apply(Request $r, string $company, int $id, FiscalProfileManager $manager)
    {
        [$tenant, $userId] = $this->context($r, $manager);
        $data = $this->bulkData($r);
        $r->validate(['request_key' => 'required|uuid', 'expected_hash' => 'required|string|size:64', 'confirm' => 'required|accepted']);
        $summary = $manager->apply($tenant, $userId, $id, $data['product_ids'], $r->string('expected_hash')->toString(), $r->string('request_key')->toString(), $r->boolean('replace_existing'));
        return redirect()->route('fiscal.profiles.index', ['company' => $company])->with('success', 'Perfil associado a '.$summary['applied'].' produto(s); '.$summary['skipped'].' preservado(s).');
    }

    private function bulkData(Request $r): array
    {
        return $r->validate(['product_ids' => 'required|array|min:1|max:200', 'product_ids.*' => 'required|integer|min:1', 'replace_existing' => 'nullable|boolean', 'tenant_id' => 'prohibited']);
    }

    private function bulkView(int $id, ?array $preview = null)
    {
        return view('fiscal.profiles.bulk', ['profile' => FiscalTaxProfile::findOrFail($id), 'products' => Product::where('type', 'product')->orderBy('name')->get(), 'groups' => ProductGroup::orderBy('name')->get(), 'preview' => $preview]);
    }

    public function bindProduct(Request $r, string $company, int $id, FiscalProfileManager $manager)
    {
        [$tenant, $userId] = $this->context($r, $manager);
        $data = $r->validate(['profile_id' => 'nullable|integer|min:1', 'fiscal_gtin' => 'nullable|string|max:14', 'fiscal_cest' => 'nullable|digits:7', 'request_key' => 'required|uuid', 'expected_hash' => 'required|string|size:64', 'tenant_id' => 'prohibited']);
        $manager->bindProduct($tenant, $userId, $id, isset($data['profile_id']) ? (int) $data['profile_id'] : null, $data['fiscal_gtin'] ?? null, $data['fiscal_cest'] ?? null, $data['expected_hash'], $data['request_key']);
        return back()->with('success', 'Vínculo e identidade fiscal salvos. Uma exceção individual existente continua tendo prioridade.');
    }

    public function assignGroup(Request $r, string $company, int $id, FiscalProfileManager $manager)
    {
        [$tenant, $userId] = $this->context($r, $manager);
        $data = $r->validate(['profile_id' => 'nullable|integer|min:1', 'request_key' => 'required|uuid', 'expected_hash' => 'required|string|size:64', 'tenant_id' => 'prohibited']);
        $manager->assignGroup($tenant, $userId, $id, isset($data['profile_id']) ? (int) $data['profile_id'] : null, $data['expected_hash'], $data['request_key']);
        return back()->with('success', 'Padrão fiscal do grupo salvo. Perfis diretos e exceções individuais foram preservados.');
    }

    public function clearIndividual(Request $r, string $company, int $id, FiscalProfileManager $manager)
    {
        [$tenant, $userId] = $this->context($r, $manager);
        $data = $r->validate(['consent' => 'required|accepted', 'request_key' => 'required|uuid', 'expected_hash' => 'required|string|size:64', 'tenant_id' => 'prohibited']);
        $manager->clearIndividual($tenant, $userId, $id, $data['expected_hash'], $data['request_key']);
        return back()->with('success', 'Exceção individual retirada com auditoria. O perfil ativo passa a valer para novas conferências e emissões.');
    }
}
