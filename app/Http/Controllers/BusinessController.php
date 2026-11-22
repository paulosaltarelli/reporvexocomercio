<?php

namespace App\Http\Controllers;

use App\Fiscal\TaxpayerDocument;
use App\Models\BusinessProfile;
use App\Models\FiscalCredential;
use App\Models\FiscalIssuer;
use App\Models\FiscalModelConfiguration;
use App\Models\FiscalSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class BusinessController extends Controller
{
    private function admin(Request $r): void
    {
        app(\App\Access\AccessProfiles::class)->authorize($r);
    }

    public function lookupRegistry(Request $request, \App\Services\CustomerRegistryLookup $registry)
    {
        $this->admin($request);
        $data = $request->validate(['document' => 'required|string|max:24']);
        $customer = $registry->find($data['document']);
        $mapping = [
            'name' => 'legal_name', 'trade_name' => 'trade_name', 'email' => 'email', 'phone' => 'phone',
            'fiscal_street' => 'street', 'fiscal_number' => 'number', 'fiscal_district' => 'district',
            'fiscal_city' => 'city', 'fiscal_state' => 'state', 'fiscal_postal_code' => 'postal_code',
            'fiscal_municipality_code' => 'municipality_code',
        ];
        $fields = [];
        foreach ($mapping as $source => $target) {
            if (isset($customer[$source])) {
                $fields[$target] = $customer[$source];
            }
        }
        if (isset($fields['number']) && mb_strlen($fields['number']) > 20) {
            unset($fields['number']);
        }

        return response()->json(['fields' => $fields])->header('Cache-Control', 'no-store');
    }

    public function fiscal(Request $r)
    {
        return $this->form($r);
    }

    public function form(Request $r)
    {
        $this->admin($r);
        return view('business', $this->viewData($r));
    }

    public function viewData(Request $r): array
    {
        $profile = BusinessProfile::find(1) ?? new BusinessProfile(['legal_name' => $r->attributes->get('tenant')->name]);
        if (!config('fiscal.product_invoices_enabled', false)) return ['profile' => $profile];
        $issuer = FiscalIssuer::where('document', TaxpayerDocument::normalize((string) $profile->document))->where('environment', 'homologation')->first();
        $credential = $issuer ? FiscalCredential::where('issuer_id', $issuer->id)->first() : null;
        $sequence = $issuer && isset($issuer->validated_configuration['series']) ? DB::connection('tenant')->table('fiscal_sequences')->where('issuer_id', $issuer->id)->where('model', 65)->where('series', $issuer->validated_configuration['series'])->first() : null;

        $nfeConfiguration = $issuer ? FiscalModelConfiguration::where('issuer_id', $issuer->id)->where('model', 55)->first() : null;
        $nfeSequence = $nfeConfiguration ? DB::connection('tenant')->table('fiscal_sequences')->where('issuer_id', $issuer->id)->where('model', 55)->where('series', $nfeConfiguration->configuration['series'])->first() : null;
        $nfeNextNumber = $nfeSequence?->next_number;
        $settings = FiscalSetting::find(1);
        $draft = $settings?->configuration_draft;
        $draftMatches = is_array($draft) && ($draft['document'] ?? null) === TaxpayerDocument::normalize((string) $profile->document);
        return ['nfeConfiguration' => $nfeConfiguration, 'nfeNextNumber' => $nfeNextNumber, 'profile' => $profile, 'fiscalIssuer' => $issuer, 'fiscalCertificate' => $credential?->only(['valid_from', 'valid_until', 'certificate_fingerprint', 'validated_hash']), 'fiscalNextNumber' => $sequence?->next_number, 'fiscalSettings' => $settings, 'fiscalDraft' => $draftMatches ? ($draft['values'] ?? []) : [], 'fiscalDraftPresent' => $draftMatches];
    }

    public function save(Request $r)
    {
        $this->admin($r);
        $d = $r->validate(['legal_name' => 'required|string|max:180', 'trade_name' => 'nullable|string|max:180', 'document' => 'nullable|string|max:18', 'municipal_registration' => 'nullable|string|max:30', 'state_registration' => 'nullable|string|max:30', 'tax_regime' => 'nullable|in:mei,simples,presumido,real', 'email' => 'nullable|email|max:180', 'phone' => 'nullable|string|max:30', 'street' => 'nullable|string|max:180', 'number' => 'nullable|string|max:20', 'district' => 'nullable|string|max:100', 'city' => 'nullable|string|max:100', 'state' => 'nullable|in:AC,AL,AP,AM,BA,CE,DF,ES,GO,MA,MT,MS,MG,PA,PB,PR,PE,PI,RJ,RN,RS,RO,RR,SC,SP,SE,TO', 'postal_code' => 'nullable|string|max:9', 'municipality_code' => 'nullable|digits:7', 'logo'=>'nullable|image|mimes:png,jpg,jpeg,webp|max:2048|dimensions:min_width=48,min_height=48,max_width=4096,max_height=4096','remove_logo'=>'nullable|boolean']);
        $tenantId=$r->attributes->get('tenant')->id;
        $remove=$r->boolean('remove_logo');$file=$r->file('logo');
        if($remove&&$file)throw ValidationException::withMessages(['logo'=>'Escolha enviar uma nova logo ou remover a atual.']);
        unset($d['logo'],$d['remove_logo']);$newPath=null;$oldPath=null;$disk=Storage::disk('local');
        if($file){
            $image=@imagecreatefromstring($file->get());
            if(!$image)throw ValidationException::withMessages(['logo'=>'Não foi possível ler a imagem enviada.']);
            $width=imagesx($image);$height=imagesy($image);$scale=min(1,1200/$width,600/$height);
            if($scale<1){$resized=imagecreatetruecolor(max(1,(int)round($width*$scale)),max(1,(int)round($height*$scale)));imagealphablending($resized,false);imagesavealpha($resized,true);$transparent=imagecolorallocatealpha($resized,0,0,0,127);imagefill($resized,0,0,$transparent);imagecopyresampled($resized,$image,0,0,0,0,imagesx($resized),imagesy($resized),$width,$height);imagedestroy($image);$image=$resized;}
            imagesavealpha($image,true);ob_start();$encoded=imagepng($image);$png=ob_get_clean();imagedestroy($image);
            if(!$encoded||!is_string($png))throw ValidationException::withMessages(['logo'=>'Não foi possível preparar a logo.']);
            $newPath='tenants/'.$tenantId.'/branding/'.bin2hex(random_bytes(16)).'.png';
            if(!$disk->put($newPath,$png,'private'))throw ValidationException::withMessages(['logo'=>'Não foi possível salvar a logo.']);
        }
        try{
            DB::connection('tenant')->transaction(function()use($r,$d,$remove,$newPath,&$oldPath){
                app(\App\Services\CompanyOperations::class)->invalidateCompany((int)$r->attributes->get('currentUser')->id);
                $profile=BusinessProfile::whereKey(1)->lockForUpdate()->first()??new BusinessProfile;
                $profile->id=1;$oldPath=$profile->logo_path;$profile->fill($d);
                if($newPath||$remove){$profile->logo_path=$newPath;$profile->logo_updated_at=now();}
                $profile->save();
            },3);
        }catch(\Throwable $e){if($newPath)$disk->delete($newPath);throw $e;}
        if(($newPath||$remove)&&$oldPath&&$this->ownedPath($oldPath,$tenantId))$disk->delete($oldPath);

        if ($r->routeIs('setup.company.save')) return redirect()->route('setup',['company'=>$r->route('company'),'step'=>2])->with('success','Dados da empresa salvos. Continue com a operação.');
        return back()->with('success', 'Dados e identidade da empresa atualizados.');
    }
    public function publishCatalog(Request $request, \App\Services\CompanyOperations $operations)
    {
        $this->admin($request);
        $data = $request->validate([
            'visibility' => 'required|in:public,private', 'version' => 'required|integer|min:0',
            'publish_all' => 'accepted_if:visibility,public',
        ]);
        $published = $data['visibility'] === 'public';
        $operations->publishCatalog($published, (int) $data['version'], $request->attributes->get('currentUser')->id);
        return redirect()->route('business', ['company' => $request->route('company')])
            ->withFragment('catalogo-publico')
            ->with('success', $published ? 'Catálogo publicado.' : 'Catálogo retirado do ar.');
    }

    public function logo(Request $r){
        $tenantId=$r->attributes->get('tenant')->id;$path=BusinessProfile::whereKey(1)->value('logo_path');
        if($path&&$this->ownedPath($path,$tenantId)&&Storage::disk('local')->exists($path))return $this->privateImage(Storage::disk('local')->path($path),'image/png');
        return $this->privateImage(public_path('brand/vendra-logo.svg'),'image/svg+xml');
    }
    private function privateImage(string $path,string $mime){
        $response=response()->file($path,['Content-Type'=>$mime,'X-Content-Type-Options'=>'nosniff']);
        $response->setPrivate();$response->headers->addCacheControlDirective('no-store');
        return $response;
    }
    private function ownedPath(string $path,int $tenantId):bool{
        return (bool)preg_match('#^tenants/'.preg_quote((string)$tenantId,'#').'/branding/[a-f0-9]{32}\.png$#D',$path);
    }
}
