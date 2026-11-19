<?php

namespace App\Services;

final class CustomerFields
{
    public const RULES = [
        'name'=>'required|string|max:180', 'document'=>'nullable|string|max:20',
        'phone'=>'nullable|string|max:30', 'email'=>'nullable|email|max:180', 'address'=>'nullable|string|max:1000',
        'trade_name' => 'nullable|string|max:180',
        'legal_nature' => 'nullable|string|max:180',
        'opened_on' => 'nullable|date_format:Y-m-d',
        'registration_status' => 'nullable|string|max:60',
        'primary_cnae' => 'nullable|digits:7',
        'fiscal_street' => 'nullable|string|max:60',
        'fiscal_number' => 'nullable|string|max:60',
        'fiscal_complement' => 'nullable|string|max:60',
        'fiscal_district' => 'nullable|string|max:60',
        'fiscal_city' => 'nullable|string|max:60',
        'fiscal_state' => 'nullable|in:AC,AL,AP,AM,BA,CE,DF,ES,GO,MA,MT,MS,MG,PA,PB,PR,PE,PI,RJ,RN,RS,RO,RR,SC,SP,SE,TO',
        'fiscal_postal_code' => 'nullable|digits:8',
        'fiscal_municipality_code' => 'nullable|digits:7',
        'fiscal_state_registration' => 'nullable|digits_between:2,14',
    ];

    public static function normalize(array $input): array
    {
        foreach (['fiscal_postal_code','primary_cnae','fiscal_state_registration'] as $field) {
            if (is_string($input[$field] ?? null)) $input[$field] = str_replace(['.', '-', '/', ' '], '', $input[$field]);
        }
        if (is_string($input['fiscal_state'] ?? null)) $input['fiscal_state'] = strtoupper($input['fiscal_state']);
        return $input;
    }
}
