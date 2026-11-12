<?php

namespace App\Http\Controllers;

use App\Fiscal\FiscalReadiness;
use App\Fiscal\PrepareFiscalReview;
use App\Fiscal\Snapshot;
use App\Models\FiscalDocument;
use App\Models\FiscalPreparation;
use App\Models\FiscalSetting;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FiscalController extends Controller
{
    private function admin(Request $request): void
    {
        abort_unless($request->attributes->get('currentUser')->role === 'admin', 403);
    }

    public function index(Request $request, FiscalReadiness $readiness)
    {
        $this->admin($request);

        return view('fiscal.index', [
            'settings' => FiscalSetting::find(1),
            'report' => $readiness->inspect($request->attributes->get('tenant')),
            'preparations' => FiscalPreparation::latest('id')->paginate(15),
            'documents' => FiscalDocument::latest('id')->limit(20)->get(),
            'taxProducts' => Product::where('type', 'product')->orderBy('name')->limit(100)->get(),
            'voids' => DB::connection('tenant')->table('fiscal_voids')->latest('id')->limit(20)->get(),
        ]);
    }

    public function save(Request $request)
    {
        $this->admin($request);
        $data = $request->validate([
            'environment' => 'required|in:homologation', 'provider' => 'required|in:focus',
            'nfce_accredited' => 'required|boolean', 'operation' => 'nullable|in:presential,delivery,both',
            'accountant_name' => 'nullable|string|max:180', 'accountant_email' => 'nullable|email|max:180',
            'tenant_id' => 'prohibited', 'secret_reference' => 'prohibited', 'token' => 'prohibited',
            'certificate' => 'prohibited', 'csc' => 'prohibited', 'fiscal_production_approved_at' => 'prohibited',
        ]);
        $settings = FiscalSetting::find(1) ?? new FiscalSetting;
        $settings->id = 1;
        $settings->fill($data);
        $settings->updated_by = $request->attributes->get('currentUser')->id;
        $settings->save();

        return back()->with('success', 'Preferências de homologação salvas. Certificado e credenciais são configurados em Minha empresa.');
    }

    public function sale(Request $request, string $company, int $id, FiscalReadiness $readiness)
    {
        $this->admin($request);
        $sale = Sale::findOrFail($id);

        return view('fiscal.sale', ['sale' => $sale, 'report' => $readiness->inspect($request->attributes->get('tenant'), $sale)]);
    }

    public function prepare(Request $request, string $company, int $id, PrepareFiscalReview $prepare)
    {
        $this->admin($request);
        $data = $request->validate(['request_key' => 'required|uuid', 'tenant_id' => 'prohibited']);
        $preparation = $prepare->prepare($request->attributes->get('tenant'), $id, $request->attributes->get('currentUser')->id, $data['request_key']);

        return redirect()->route('fiscal.preparation', ['company' => $company, 'id' => $preparation->id]);
    }

    public function preparation(Request $request, string $company, int $id)
    {
        $this->admin($request);
        $preparation = FiscalPreparation::where('tenant_id', $request->attributes->get('tenant')->id)->findOrFail($id);
        abort_unless(hash_equals($preparation->snapshot_sha256, Snapshot::hash($preparation->snapshot)), 409, 'Integridade da conferência fiscal inválida.');

        return view('fiscal.preparation', ['preparation' => $preparation, 'report' => ['snapshot' => $preparation->snapshot, 'blockers' => $preparation->blockers]]);
    }
}
