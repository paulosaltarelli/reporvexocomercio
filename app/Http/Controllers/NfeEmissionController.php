<?php
namespace App\Http\Controllers;
use App\Fiscal\NfeCompanyConfiguration;
use App\Fiscal\NfeRecipient;
use App\Fiscal\PrivateFiscalFiles;
use App\Fiscal\QueueNfe;
use App\Models\Customer;
use App\Models\FiscalDocument;
use Illuminate\Http\Request;

class NfeEmissionController extends Controller
{
    private function admin(Request $r): void { abort_unless($r->attributes->get('currentUser')->role === 'admin', 403); }
    public function configure(Request $r, NfeCompanyConfiguration $service)
    {
        $this->admin($r);
        $data = $r->validate(['environment'=>'required|in:homologation','series'=>'required|integer|min:0|max:889','next_number'=>'required|integer|min:1|max:999999999',
            'timezone'=>'required|timezone','crt'=>'required|in:1,2,3,4','natureza_operacao'=>'required|string|max:60','cancellation_minutes'=>'required|integer|min:0|max:30',
            'uf_validation_reference'=>'required|string|max:180','certificate'=>'nullable|file|max:2048','certificate_password'=>'nullable|string|max:256',
            'csc'=>'prohibited','csc_id'=>'prohibited','tenant_id'=>'prohibited','model'=>'prohibited']);
        unset($data['environment'],$data['certificate'],$data['certificate_password']);
        $service->save($r->attributes->get('tenant'), $data, $r->only('certificate_password'), $r->file('certificate')?->get());
        return back()->with('success','Configuração NF-e salva. Valide o certificado e os parâmetros antes de solicitar homologação. Nenhuma nota foi enviada.');
    }
    public function validateLocal(Request $r, NfeCompanyConfiguration $service)
    {
        $this->admin($r); $r->validate(['tenant_id'=>'prohibited']);
        $service->validateLocal($r->attributes->get('tenant'));
        return back()->with('success','Configuração NF-e validada localmente, sem transmissão e sem habilitar produção.');
    }
    public function customer(Request $r, string $company, int $id)
    {
        $this->admin($r); return view('fiscal.nfe.customer',['customer'=>Customer::findOrFail($id)]);
    }
    public function saveCustomer(Request $r, string $company, int $id, NfeRecipient $service)
    {
        $this->admin($r); $r->validate(['tenant_id'=>'prohibited']);
        $service->save($r->attributes->get('tenant'), $id, $r->all(), $r->attributes->get('currentUser')->id);
        return back()->with('success','Endereço fiscal do cliente salvo para futuras NF-e. Documentos existentes não foram alterados.');
    }
    public function emit(Request $r, string $company, int $id, QueueNfe $queue)
    {
        $this->admin($r);
        $operation = $r->validate(['homologation_consent'=>'required|accepted','consumer_final'=>'required|boolean','presence'=>'required|in:1','destination_state'=>'required|alpha|size:2','pix_mode'=>'nullable|in:dynamic,static','tenant_id'=>'prohibited','model'=>'prohibited']);
        $operation['consumer_final'] = $r->boolean('consumer_final');
        $document = $queue->enqueue($r->attributes->get('tenant'), $id, $r->attributes->get('currentUser')->id, $operation);
        return redirect()->route('nfe.document',['company'=>$company,'id'=>$document->id]);
    }
    private function documentFor(Request $r, int $id): FiscalDocument
    {
        $this->admin($r); $document = FiscalDocument::where('model',55)->findOrFail($id);
        abort_unless((int)($document->snapshot['tenant_id']??0)===(int)$r->attributes->get('tenant')->id,404);
        return $document;
    }
    public function show(Request $r, string $company, int $id) { return view('fiscal.nfe.document',['document'=>$this->documentFor($r,$id)]); }
    public function consult(Request $r, string $company, int $id, QueueNfe $queue)
    {
        $document=$this->documentFor($r,$id); $queue->consult($r->attributes->get('tenant'),$document->id);
        return back()->with('success','Consulta NF-e solicitada. A consulta preserva modelo, XML e chave originais.');
    }
    public function resend(Request $r, string $company, int $id, QueueNfe $queue)
    {
        $document=$this->documentFor($r,$id); $r->validate(['same_reference_consent'=>'required|accepted']);
        $queue->resend($r->attributes->get('tenant'),$document->id,$r->attributes->get('currentUser')->id);
        return back()->with('success','Reenvio NF-e solicitado. Será feita nova consulta antes de retransmitir o mesmo XML assinado.');
    }
    public function cancel(Request $r, string $company, int $id, QueueNfe $queue)
    {
        $document=$this->documentFor($r,$id); $data=$r->validate(['reason'=>'required|string|min:15|max:255']);
        $queue->cancel($r->attributes->get('tenant'),$document->id,$data['reason']);
        return back()->with('success','Cancelamento NF-e solicitado. A situação depende da resposta oficial; estoque e financeiro permanecem preservados.');
    }
    public function download(Request $r, string $company, int $id, string $kind, PrivateFiscalFiles $files)
    {
        $document=$this->documentFor($r,$id);
        abort_unless(in_array($kind,['xml','danfe','cancel_xml'],true)&&in_array($document->state,['authorized','cancelled'],true),404);
        $bytes=$files->get($r->attributes->get('tenant')->id,$document->id,$kind); $pdf=$kind==='danfe';
        return response($bytes)->withHeaders(['Content-Type'=>$pdf?'application/pdf':'application/xml','Content-Disposition'=>'attachment; filename="nfe-'.$document->number.'-'.$kind.($pdf?'.pdf':'.xml').'"','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
}
