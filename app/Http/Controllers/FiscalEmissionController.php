<?php

namespace App\Http\Controllers;

use App\Fiscal\CompanyFiscalConfiguration;
use App\Fiscal\FiscalTransportException;
use App\Fiscal\PrivateFiscalFiles;
use App\Fiscal\QueueNfce;
use App\Fiscal\QueueVoid;
use App\Fiscal\SyncFocusCompany;
use App\Fiscal\TaxpayerDocument;
use App\Fiscal\TaxProfile;
use App\Models\BusinessProfile;
use App\Models\FiscalDocument;
use App\Models\FiscalIssuer;
use App\Models\FiscalItemRule;
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
        $data = $r->validate([
            'environment' => 'required|in:homologation', 'provider_company_id' => 'required|digits_between:1,20', 'series' => 'required|integer|min:0|max:889', 'next_number' => 'required|integer|min:1|max:999999999',
            'crt' => 'required|in:1,2,3,4', 'natureza_operacao' => 'required|string|max:60', 'identification_limit' => 'required|numeric|min:0|max:999999999.99|decimal:0,2',
            'cancellation_minutes' => 'required|integer|min:0|max:30', 'uf_validation_reference' => 'required|string|max:180', 'csc_required' => 'required|boolean',
            'certificate' => 'nullable|file|max:2048', 'certificate_password' => 'nullable|string|max:256', 'csc' => 'nullable|string|max:100', 'csc_id' => 'nullable|digits_between:1,6',
            'emission_token' => 'nullable|string|max:512', 'management_token' => 'nullable|string|max:512', 'tenant_id' => 'prohibited',
        ]);
        $secrets = $r->only(['emission_token', 'management_token', 'csc', 'csc_id', 'certificate_password']);
        foreach (['certificate', 'certificate_password', 'csc', 'csc_id', 'emission_token', 'management_token', 'environment'] as $field) {
            unset($data[$field]);
        }
        $configuration->save($r->attributes->get('tenant'), $data, $secrets, $r->file('certificate')?->get());

        return back()->with('success', 'Configuração fiscal salva de forma privada. Sincronize com o provedor antes de emitir em homologação.');
    }

    public function sync(Request $r, SyncFocusCompany $sync)
    {
        $this->admin($r);
        $r->validate(['share_certificate_consent' => 'accepted']);
        $cnpj = TaxpayerDocument::normalize((string) BusinessProfile::findOrFail(1)->document);
        $issuer = FiscalIssuer::where('document', $cnpj)->where('environment', 'homologation')->firstOrFail();
        try {
            $sync->sync($issuer);
        } catch (FiscalTransportException $e) {
            throw ValidationException::withMessages(['fiscal' => 'Não foi possível confirmar a configuração no provedor. Código: '.$e->getMessage()]);
        }

        return back()->with('success', 'Cadastro e certificado sincronizados com a Focus. Nenhuma nota foi emitida.');
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
        $operation = $r->validate(['consumer_final' => 'accepted', 'not_taxpayer' => 'accepted', 'homologation_consent' => 'accepted', 'presence' => 'required|in:1', 'destination_state' => 'required|alpha|size:2', 'tenant_id' => 'prohibited']);
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

    public function invalidate(Request $r, QueueVoid $queue)
    {
        $this->admin($r);
        $data = $r->validate(['last_number' => 'required|integer|min:1|max:999999999', 'reason' => 'required|string|min:15|max:255', 'request_key' => 'required|uuid', 'homologation_consent' => 'accepted']);
        $cnpj = TaxpayerDocument::normalize((string) BusinessProfile::findOrFail(1)->document);
        $issuer = FiscalIssuer::where('document', $cnpj)->where('environment', 'homologation')->firstOrFail();
        $queue->enqueue($r->attributes->get('tenant'), $issuer, $r->attributes->get('currentUser')->id, (int) $data['last_number'], $data['reason'], $data['request_key']);

        return back()->with('success', 'Inutilização solicitada. A faixa foi reservada e não será usada por novas emissões.');
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
