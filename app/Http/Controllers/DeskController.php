<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Document;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\Product;
use App\Services\CommercialRules;
use Illuminate\Http\Request;

class DeskController extends Controller
{
    public function index(Request $request)
    {
        $data=$request->validate(['salesperson_id'=>'nullable|integer|exists:tenant.sellers,id',
            'customer_id'=>'nullable|integer|exists:tenant.customers,id','q'=>'nullable|string|max:100']);
        $actor=$request->attributes->get('currentUser');
        $own=Seller::where('user_id',$actor->id)->first() ?? app(CommercialRules::class)->seller($actor->id);
        $selectedId=isset($data['salesperson_id'])?(int)$data['salesperson_id']:$own->id;
        abort_unless($actor->role==='admin'||$selectedId===$own->id,403);
        $salesperson=Seller::findOrFail($selectedId);
        $search=trim($data['q']??'');
        $matches=$search===''?collect():Customer::where(function($query)use($search){
            $query->where('name','like','%'.$search.'%')->orWhere('phone','like','%'.$search.'%')->orWhere('email','like','%'.$search.'%');
        })->orderBy('name')->limit(10)->get();
        $quotes=Document::with('customer')->where('salesperson_id',$selectedId)->where('type','quote')->whereIn('status',['draft','approved']);
        $sales=Sale::with('customer')->where('salesperson_id',$selectedId)->whereBetween('created_at',[now()->startOfDay(),now()->endOfDay()]);
        $completed=(clone $sales)->where('status','completed');
        return view('desk',[
            'salesperson'=>$salesperson,'sellers'=>Seller::where('active',true)->orderBy('name')->get(),
            'selectedCustomer'=>isset($data['customer_id'])?Customer::findOrFail($data['customer_id']):null,
            'customerMatches'=>$matches,'search'=>$search,'myQuotes'=>(clone $quotes)->orderByRaw("status = 'approved' DESC")->latest()->limit(8)->get(),
            'recentSales'=>(clone $sales)->latest()->limit(8)->get(),
            'metrics'=>['sales_count'=>(clone $completed)->count(),'sales_total'=>(string)(clone $completed)->sum('total'),'quotes_count'=>(clone $quotes)->count()],
            'canOperate'=>(bool)$salesperson->active,
            'products'=>Product::with('group')->where('active',true)->orderBy('name')->get(),
            'customers'=>Customer::orderBy('name')->get(),
            'currentSalesperson'=>$own,'selectedSalespersonId'=>$selectedId,
            'selectedCustomerId'=>$data['customer_id']??null,
            'unassignedQuotesCount'=>$actor->role==='admin'?Document::where('type','quote')->whereIn('status',['draft','approved'])->whereNull('salesperson_id')->count():0,
        ]);
    }
}
