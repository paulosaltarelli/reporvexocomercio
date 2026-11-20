<?php

namespace App\Http\Controllers;

use App\Fiscal\Nfse\Access;
use App\Fiscal\Nfse\HistoricalDps;
use App\Models\NfseDocument;
use Illuminate\Http\Request;

class NfseController extends Controller
{
    private function admin(Request $request): void
    {
        $user = $request->attributes->get('currentUser');
        abort_unless($user, 403);
        Access::admin($request->attributes->get('tenant'), (int) $user->id);
    }

    public function index(Request $request)
    {
        $this->admin($request);
        $documents = NfseDocument::latest('id')->paginate(20, [
            'id', 'sale_id', 'series', 'number', 'competence', 'environment', 'status', 'total', 'created_at',
        ]);

        return view('fiscal.nfse.index', compact('documents'));
    }

    public function sale(Request $request, string $company, int $id)
    {
        $this->admin($request);
        $document = NfseDocument::where('sale_id', $id)->firstOrFail(['id']);

        return redirect()->route('nfse.show', ['company' => $company, 'id' => $document->id]);
    }

    public function show(Request $request, string $company, int $id, HistoricalDps $service)
    {
        $document = $this->verifiedDocument($request, $id, $service);

        return view('fiscal.nfse.show', compact('document'));
    }

    public function xml(Request $request, string $company, int $id, HistoricalDps $service)
    {
        $document = $this->verifiedDocument($request, $id, $service);

        return response($document->signed_xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$document->dps_id.'.xml"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function verifiedDocument(Request $request, int $id, HistoricalDps $service): NfseDocument
    {
        $this->admin($request);
        $document = NfseDocument::findOrFail($id);
        $service->verify($document, $request->attributes->get('tenant'), (int) $request->attributes->get('currentUser')->id);

        return $document;
    }
}
