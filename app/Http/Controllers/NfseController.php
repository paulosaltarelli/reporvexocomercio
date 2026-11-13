<?php

namespace App\Http\Controllers;

use App\Fiscal\Nfse\Access;
use App\Fiscal\Nfse\Configuration;
use App\Fiscal\Nfse\PrepareDps;
use App\Fiscal\Nfse\Readiness;
use App\Fiscal\TaxpayerDocument;
use App\Models\BusinessProfile;
use App\Models\NfseConfiguration;
use App\Models\NfseDocument;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NfseController extends Controller
{
    private function admin(Request $request): void
    {
        $user = $request->attributes->get('currentUser');
        abort_unless($user, 403);
        Access::admin($request->attributes->get('tenant'), (int) $user->id);
    }

    private function configuration(): ?NfseConfiguration
    {
        return NfseConfiguration::where('cnpj', TaxpayerDocument::normalize((string) BusinessProfile::find(1)?->document))->first();
    }

    public function index(Request $request, Readiness $readiness)
    {
        $this->admin($request);
        $configuration = $this->configuration();
        if ($configuration) {
            $parameters = $configuration->parameters;
            $parameters['next_number'] = DB::connection('tenant')->table('nfse_sequences')->where('configuration_id', $configuration->id)->where('series', $parameters['series'])->value('next_number');
            $configuration->parameters = $parameters;
        }
        $profile = BusinessProfile::find(1);
        return view('fiscal.nfse.index', ['configuration' => $configuration, 'configurationDefaults' => ['municipality_code' => $profile?->municipality_code, 'municipal_registration' => $profile?->municipal_registration], 'report' => $readiness->inspect($configuration), 'documents' => NfseDocument::latest('id')->paginate(20)]);
    }

    public function save(Request $request, Configuration $service)
    {
        $this->admin($request);
        try {
            $request->validate(['certificate' => 'nullable|file|max:2048', 'certificate_password' => 'nullable|string|max:1024', 'tenant_id' => 'prohibited', 'environment' => 'prohibited', 'endpoint' => 'prohibited']);
            $service->save($request->attributes->get('tenant'), $request->except(['certificate', '_token']), $request->hasFile('certificate') ? file_get_contents($request->file('certificate')->getRealPath()) : null, (int) $request->attributes->get('currentUser')->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput($request->except(['certificate', 'certificate_password', '_token']));
        }
        return back()->with('success', 'Configuração própria da NFS-e salva. Somente preparação local; transmissão não disponível.');
    }

    public function sale(Request $request, string $company, int $id, Readiness $readiness)
    {
        $this->admin($request);
        $sale = Sale::with('customer', 'items')->findOrFail($id);
        $configuration = $this->configuration();
        return view('fiscal.nfse.sale', ['sale' => $sale, 'configuration' => $configuration, 'report' => $readiness->inspect($configuration, $sale), 'requestKey' => (string) Str::uuid()]);
    }

    public function prepare(Request $request, string $company, int $id, PrepareDps $service)
    {
        $this->admin($request);
        $data = $request->validate(['request_key' => 'required|uuid', 'competence' => 'required|date_format:Y-m-d', 'operation_scope_confirmed' => 'required|accepted', 'tenant_id' => 'prohibited', 'environment' => 'prohibited']);
        $document = $service->prepare($request->attributes->get('tenant'), $id, (int) $request->attributes->get('currentUser')->id, $data);
        return redirect()->route('nfse.show', ['company' => $company, 'id' => $document->id]);
    }

    public function show(Request $request, string $company, int $id, PrepareDps $service)
    {
        $this->admin($request);
        $document = NfseDocument::findOrFail($id);
        $service->verify($document, $request->attributes->get('tenant'), (int) $request->attributes->get('currentUser')->id);
        return view('fiscal.nfse.show', ['document' => $document, 'report' => ['blockers' => [$document->blocking_reason], 'transport_ready' => false]]);
    }

    public function xml(Request $request, string $company, int $id, PrepareDps $service)
    {
        $this->admin($request);
        $document = NfseDocument::findOrFail($id);
        $service->verify($document, $request->attributes->get('tenant'), (int) $request->attributes->get('currentUser')->id);
        return response($document->signed_xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$document->dps_id.'.xml"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function unavailable(Request $request, string $company, int $id)
    {
        $this->admin($request);
        NfseDocument::findOrFail($id);
        throw ValidationException::withMessages(['nfse' => Readiness::TRANSPORT_BLOCKER]);
    }
}
