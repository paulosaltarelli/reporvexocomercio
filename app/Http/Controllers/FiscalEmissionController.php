<?php

namespace App\Http\Controllers;

use App\Fiscal\CompanyFiscalConfiguration;
use App\Fiscal\FiscalTransportException;
use App\Fiscal\PrivateFiscalFiles;
use App\Fiscal\QueueNfce;
use App\Fiscal\QueueVoid;
use App\Fiscal\TaxpayerDocument;
use App\Fiscal\TaxProfile;
use App\Models\BusinessProfile;
use App\Models\FiscalDocument;
use App\Models\FiscalIssuer;
use App\Models\FiscalItemRule;
use App\Models\FiscalSetting;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FiscalEmissionController extends Controller
{
    private function admin(Request $r): void
    {
        abort_unless($r->attributes->get('currentUser')->role === 'admin', 403);
    }

    public function configure(Request $r, CompanyFiscalConfiguration $configuration)
    {
        $this->admin($r);
        $r->validate(['intent' => 'nullable|in:draft,activate']);
        if ($r->input('intent') === 'draft') {
            return $this->saveDraft($r);
        }
        $data = $r->validate([
            'environment' => 'required|in:homologation', 'provider_company_id' => 'prohibited', 'series' => 'required|integer|min:0|max:889', 'next_number' => 'required|integer|min:1|max:999999999',
            'timezone' => 'required|timezone', 'crt' => 'required|in:1,2,3,4', 'natureza_operacao' => 'required|string|max:60', 'identification_limit' => 'required|numeric|min:0|max:999999999.99|decimal:0,2',
            'cancellation_minutes' => 'required|integer|min:0|max:30', 'uf_validation_reference' => 'required|string|max:180', 'csc_required' => 'required|accepted',
            'certificate' => 'nullable|file|max:2048', 'certificate_password' => 'nullable|string|max:256', 'csc' => 'nullable|string|max:100', 'csc_id' => 'nullable|digits_between:1,6',
            'emission_token' => 'prohibited', 'management_token' => 'prohibited', 'tenant_id' => 'prohibited',
        ]);
        $secrets = $r->only(['csc', 'csc_id', 'certificate_password']);
        foreach (['certificate', 'certificate_password', 'csc', 'csc_id', 'emission_token', 'management_token', 'environment', 'provider_company_id'] as $field) {
            unset($data[$field]);
        }
        $configuration->save($r->attributes->get('tenant'), $data, $secrets, $r->file('certificate')?->get());

        return back()->with('success', 'Configuração fiscal salva de forma privada. Valide o certificado e a configuração localmente antes da homologação.');
    }

    private function saveDraft(Request $r)
    {
        $data = $r->validate([
            'environment' => 'required|in:homologation',
            'series' => 'nullable|integer|min:0|max:889', 'next_number' => 'nullable|integer|min:1|max:999999999',
            'timezone' => 'nullable|timezone', 'crt' => 'nullable|in:1,2,3,4',
            'natureza_operacao' => 'nullable|string|max:60',
            'identification_limit' => 'nullable|numeric|min:0|max:999999999.99|decimal:0,2',
            'cancellation_minutes' => 'nullable|integer|min:0|max:30',
            'uf_validation_reference' => 'nullable|string|max:180', 'csc_required' => 'sometimes|nullable|accepted',
            'certificate' => 'prohibited', 'certificate_password' => 'prohibited', 'csc' => 'prohibited', 'csc_id' => 'prohibited',
            'emission_token' => 'prohibited', 'management_token' => 'prohibited', 'provider_company_id' => 'prohibited',
            'tenant_id' => 'prohibited', 'fiscal_production_approved_at' => 'prohibited',
        ]);
        $data = array_intersect_key($data, array_flip(['series', 'next_number', 'timezone', 'crt', 'natureza_operacao', 'identification_limit', 'cancellation_minutes', 'uf_validation_reference', 'csc_required']));
        $tenant = $r->attributes->get('tenant');
        abort_unless($tenant->active && DB::connection('tenant')->getDatabaseName() === $tenant->db_name, 403);
        DB::connection('tenant')->transaction(function () use ($r, $data) {
            $profile = BusinessProfile::whereKey(1)->lockForUpdate()->first();
            $document = TaxpayerDocument::normalize((string) $profile?->document);
            if (! TaxpayerDocument::cnpj($document)) {
                throw ValidationException::withMessages(['document' => 'Salve primeiro o CNPJ válido em Identificação da empresa.']);
            }
            $settings = FiscalSetting::whereKey(1)->lockForUpdate()->first() ?? new FiscalSetting;
            $settings->id = 1;
            $settings->provider = 'sefaz_direct';
            $settings->environment = 'homologation';
            $settings->configuration_draft = ['document' => $document, 'values' => $data];
            $settings->updated_by = $r->attributes->get('currentUser')->id;
            $settings->save();
        }, 3);
        return back()->with('success', 'Rascunho fiscal salvo sem certificado. Nenhuma numeração foi reservada e nenhuma emissão foi habilitada.');
    }

    public function validateLocal(Request $r, CompanyFiscalConfiguration $configuration)
    {
        $this->admin($r);
        $r->validate(['tenant_id' => 'prohibited']);
        $configuration->validateLocal($r->attributes->get('tenant'));
        return back()->with('success', 'Certificado e configuração validados localmente. Nenhuma nota foi enviada e a produção permanece bloqueada.');
    }

    public function product(Request $r, string $company, int $id)
    {
        $this->admin($r);
        $product = Product::where('type', 'product')->findOrFail($id);

        return view('fiscal.product', ['product' => $product, 'rule' => FiscalItemRule::where('product_id', $id)->first()]);
    }

    public function saveProduct(Request $r, string $company, int $id, TaxProfile $profile)
    {
        $this->admin($r);
        Product::where('type', 'product')->findOrFail($id);
        $reference = $r->validate(['accountant_reference' => 'required|string|max:180']);
        $parameters = $profile->validate($r->all());
        FiscalItemRule::updateOrCreate(['product_id' => $id], ['parameters' => $parameters, 'accountant_reference' => $reference['accountant_reference'], 'validated_by' => $r->attributes->get('currentUser')->id]);

        return back()->with('success', 'Regra fiscal do produto salva com referência da validação contábil.');
    }

    public function emit(Request $r, string $company, int $id, QueueNfce $queue)
    {
        $this->admin($r);
        $operation = $r->validate(['consumer_final' => 'accepted', 'not_taxpayer' => 'accepted', 'homologation_consent' => 'accepted', 'presence' => 'required|in:1', 'destination_state' => 'required|alpha|size:2', 'pix_mode' => 'nullable|in:dynamic,static', 'tenant_id' => 'prohibited']);
        $operation['consumer_final'] = $r->boolean('consumer_final');
        $operation['not_taxpayer'] = $r->boolean('not_taxpayer');
        $doc = $queue->enqueue($r->attributes->get('tenant'), $id, $r->attributes->get('currentUser')->id, $operation);

        return redirect()->route('fiscal.document', ['company' => $company, 'id' => $doc->id]);
    }

    private function documentFor(Request $r, int $id): FiscalDocument
    {
        $this->admin($r);
        $doc = FiscalDocument::findOrFail($id);
        abort_unless((int) ($doc->snapshot['tenant_id'] ?? 0) === (int) $r->attributes->get('tenant')->id, 404);

        return $doc;
    }

    public function show(Request $r, string $company, int $id)
    {
        return view('fiscal.document', ['document' => $this->documentFor($r, $id)]);
    }

    public function consult(Request $r, string $company, int $id, QueueNfce $queue)
    {
        $doc = $this->documentFor($r, $id);
        $queue->consult($r->attributes->get('tenant'), $doc->id);

        return back()->with('success', 'Consulta solicitada. O worker fiscal atualizará a situação.');
    }

    public function resend(Request $r, string $company, int $id, QueueNfce $queue)
    {
        $doc = $this->documentFor($r, $id);
        $r->validate(['same_reference_consent' => 'accepted']);
        $queue->resend($r->attributes->get('tenant'), $doc->id, $r->attributes->get('currentUser')->id);

        return back()->with('success', 'Reenvio solicitado com a mesma referência, número e dados. O worker consultará novamente antes de transmitir.');
    }

    public function cancel(Request $r, string $company, int $id, QueueNfce $queue)
    {
        $doc = $this->documentFor($r, $id);
        $data = $r->validate(['reason' => 'required|string|min:15|max:255']);
        $queue->cancel($r->attributes->get('tenant'), $doc->id, $data['reason']);

        return back()->with('success', 'Cancelamento fiscal solicitado. Estoque e financeiro não foram alterados.');
    }

    public function download(Request $r, string $company, int $id, string $kind, PrivateFiscalFiles $files)
    {
        $doc = $this->documentFor($r, $id);
        abort_unless(in_array($kind, ['xml', 'danfe', 'cancel_xml'], true) && in_array($doc->state, ['authorized', 'cancelled'], true), 404);
        $bytes = $files->get($r->attributes->get('tenant')->id, $doc->id, $kind);
        $pdf = $kind === 'danfe';

        return response($bytes)->withHeaders(['Content-Type' => $pdf ? 'application/pdf' : 'application/xml', 'Content-Disposition' => 'attachment; filename="nfce-'.$doc->number.'-'.$kind.($pdf ? '.pdf' : '.xml').'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function invalidate(Request $r)
    {
        $this->admin($r);
        throw ValidationException::withMessages(['fiscal' => 'Inutilização direta ainda não habilitada. Nenhuma faixa foi reservada ou enviada.']);
    }

    public function voidXml(Request $r, string $company, int $id)
    {
        $this->admin($r);
        $void = DB::connection('tenant')->table('fiscal_voids')->find($id);
        abort_unless($void && $void->state === 'voided' && $void->encrypted_xml, 404);
        $xml = base64_decode(Crypt::decryptString($void->encrypted_xml), true);
        abort_unless(is_string($xml) && hash_equals($void->xml_sha256, hash('sha256', $xml)), 409);

        return response($xml)->withHeaders(['Content-Type' => 'application/xml', 'Content-Disposition' => 'attachment; filename="inutilizacao-'.$id.'.xml"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
