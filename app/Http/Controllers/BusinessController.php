<?php

namespace App\Http\Controllers;

use App\Models\BusinessProfile;
use Illuminate\Http\Request;

class BusinessController extends Controller
{
    private function admin(Request $r): void
    {
        abort_unless($r->attributes->get('currentUser')->role === 'admin', 403);
    }

    public function form(Request $r)
    {
        $this->admin($r);

        return view('business', ['profile' => BusinessProfile::find(1) ?? new BusinessProfile(['legal_name' => $r->attributes->get('tenant')->name])]);
    }

    public function save(Request $r)
    {
        $this->admin($r);
        $d = $r->validate(['legal_name' => 'required|string|max:180', 'trade_name' => 'nullable|string|max:180', 'document' => 'nullable|string|max:18', 'municipal_registration' => 'nullable|string|max:30', 'state_registration' => 'nullable|string|max:30', 'tax_regime' => 'nullable|in:mei,simples,presumido,real', 'email' => 'nullable|email|max:180', 'phone' => 'nullable|string|max:30', 'street' => 'nullable|string|max:180', 'number' => 'nullable|string|max:20', 'district' => 'nullable|string|max:100', 'city' => 'nullable|string|max:100', 'state' => 'nullable|in:AC,AL,AP,AM,BA,CE,DF,ES,GO,MA,MT,MS,MG,PA,PB,PR,PE,PI,RJ,RN,RS,RO,RR,SC,SP,SE,TO', 'postal_code' => 'nullable|string|max:9', 'municipality_code' => 'nullable|digits:7']);
        $p = BusinessProfile::find(1) ?? new BusinessProfile;
        $p->id = 1;
        $p->fill($d)->save();

        return back()->with('success', 'Dados da empresa salvos. A emissão fiscal continua indisponível nesta versão.');
    }
}
