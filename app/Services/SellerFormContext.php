<?php

namespace App\Services;

use App\Models\Seller;
use App\Models\User;

/** Defaults for the two existing entry forms; submitted attribution is validated again by the domain. */
class SellerFormContext
{
    public function forUser(User $actor, ?int $requestedId = null): array
    {
        $current = Seller::where('user_id',$actor->id)->first() ?? app(CommercialRules::class)->seller($actor->id);
        $selected = $requestedId !== null ? app(CommercialRules::class)->seller($actor->id,null,$requestedId) : $current;
        return ['sellers'=>Seller::where('active',true)->orderBy('name')->get(),
            'currentSalesperson'=>$current,'selectedSalespersonId'=>$selected->active?$selected->id:null];
    }
}
