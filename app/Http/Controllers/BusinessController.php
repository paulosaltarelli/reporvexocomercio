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
        abort_unless($r->attributes->get('currentUser')->role === 'admin', 403);
    }

    public function form(Request $r)
    {
        $this->admin($r);
        $profile = BusinessProfile::find(1) ?? new BusinessProfile(['legal_name' => $r->attributes->get('tenant')->name]);
        $issuer = FiscalIssuer::where('document', TaxpayerDocument::normalize((string) $profile->document))->where('environment', 'homologation')->first();
        $credential = $issuer ? FiscalCredential::where('issuer_id', $issuer->id)->first() : null;
        $sequence = $issuer && isset($issuer->validated_configuration['series']) ? DB::connection('tenant')->table('fiscal_sequences')->where('issuer_id', $issuer->id)->where('model', 65)->where('series', $issuer->validated_configuration['series'])->first() : null;

        $nfeConfiguration = $issuer ? FiscalModelConfiguration::where('issuer_id', $issuer->id)->where('model', 55)->first() : null;
        $nfeSequence = $nfeConfiguration ? DB::connection('tenant')->table('fiscal_sequences')->where('issuer_id', $issuer->id)->where('model', 55)->where('series', $nfeConfiguration->configuration['series'])->first() : null;
        $nfeNextNumber = $nfeSequence?->next_number;
        $settings = FiscalSetting::find(1);
        $draft = $settings?->configuration_draft;
        $draftMatches = is_array($draft) && ($draft['document'] ?? null) === TaxpayerDocument::normalize((string) $profile->document);
        return view('business', ['nfeConfiguration' => $nfeConfiguration, 'nfeNextNumber' => $nfeNextNumber, 'profile' => $profile, 'fiscalIssuer' => $issuer, 'fiscalCertificate' => $credential?->only(['valid_from', 'valid_until', 'certificate_fingerprint', 'validated_hash']), 'fiscalNextNumber' => $sequence?->next_number, 'fiscalSettings' => $settings, 'fiscalDraft' => $draftMatches ? ($draft['values'] ?? []) : [], 'fiscalDraftPresent' => $draftMatches]);
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
            DB::connection('tenant')->transaction(function()use($d,$remove,$newPath,&$oldPath){
                $profile=BusinessProfile::whereKey(1)->lockForUpdate()->first()??new BusinessProfile;
                $profile->id=1;$oldPath=$profile->logo_path;$profile->fill($d);
                if($newPath||$remove){$profile->logo_path=$newPath;$profile->logo_updated_at=now();}
                $profile->save();
            },3);
        }catch(\Throwable $e){if($newPath)$disk->delete($newPath);throw $e;}
        if(($newPath||$remove)&&$oldPath&&$this->ownedPath($oldPath,$tenantId))$disk->delete($oldPath);

        return back()->with('success', 'Dados e identidade da empresa atualizados.');
    }
    public function logo(Request $r){
        $profile=BusinessProfile::find(1);$tenantId=$r->attributes->get('tenant')->id;$path=$profile?->logo_path;
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
