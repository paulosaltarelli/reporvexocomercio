<?php

namespace App\Http\Controllers;

use App\Services\SupermarketDrafts;
use Illuminate\Http\Request;

final class SupermarketDraftController extends Controller
{
    public function preview(Request $request, string $company, int $cart, SupermarketDrafts $drafts)
    {
        return response()->json($drafts->preview($cart, $request->all(), $request->attributes->get('currentUser')->id))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, string $company, int $cart, SupermarketDrafts $drafts)
    {
        return response()->json($drafts->import($cart, $request->all(), $request->attributes->get('currentUser')->id))
            ->header('Cache-Control', 'private, no-store');
    }
}
