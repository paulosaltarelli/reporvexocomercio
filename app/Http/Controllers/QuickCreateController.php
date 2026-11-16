<?php
namespace App\Http\Controllers;
use App\Services\QuickCreate;
use Illuminate\Http\Request;
final class QuickCreateController extends Controller
{
    public function store(Request $r,string $company,string $kind,QuickCreate $service)
    {
        return response()->json(['item'=>$service->create($kind,$r->except('_token'),(int)$r->attributes->get('currentUser')->id)]);
    }
}
