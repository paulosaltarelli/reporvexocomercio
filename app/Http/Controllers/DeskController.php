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
        if($request->filled('document_number')){
            $print = $request->input('action') === 'print_os';
            abort_unless($request->attributes->get('currentUser')->allows($print ? 'documents.view' : 'documents.invoice'),403);
            $lookup=$request->validate(['document_number'=>'required|integer|min:1']);
            $document=Document::findOrFail($lookup['document_number']);
            if ($print) {
                if ($document->type !== 'work_order') throw \Illuminate\Validation\ValidationException::withMessages(['document_number'=>'Este número é de um orçamento. Informe o número da OS.']);
                return redirect()->route('document.show',['company'=>$request->route('company'),'id'=>$document->id,'imprimir'=>1]);
            }
            if ($document->sale_id && $document->type === 'work_order') {
                return redirect()->route('document.show',['company'=>$request->route('company'),'id'=>$document->id]);
            }
            if($document->sale_id)return redirect()->route('sale.show',['company'=>$request->route('company'),'id'=>$document->sale_id]);
            if(!in_array($document->status,['approved','completed'],true))throw \Illuminate\Validation\ValidationException::withMessages(['document_number'=>'Aprove o orçamento ou conclua a OS antes de receber no caixa.']);
            return redirect()->to(route('document.show',['company'=>$request->route('company'),'id'=>$document->id]).'#document-checkout');
        }
        $data=$request->validate(['salesperson_id'=>'nullable|integer|exists:tenant.sellers,id',
            'customer_id'=>'nullable|integer|exists:tenant.customers,id','q'=>'nullable|string|max:100']);
        $actor=$request->attributes->get('currentUser');
        $own=Seller::where('user_id',$actor->id)->first() ?? app(CommercialRules::class)->seller($actor->id);
        $selectedId=isset($data['salesperson_id'])?(int)$data['salesperson_id']:$own->id;
        abort_unless($actor->allows('sales.seller')||$selectedId===$own->id,403);
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
            'readyDocuments'=>Document::with('customer')->whereNull('sale_id')->where(fn($q)=>$q->where(fn($q)=>$q->where('type','quote')->where('status','approved'))->orWhere(fn($q)=>$q->where('type','work_order')->where('status','completed')))->latest()->limit(10)->get(),
            'canOperate'=>(bool)$salesperson->active,
            'products'=>Product::with('group')->where('active',true)->when(app(\App\Services\CompanyOperations::class)->current()['catalog_mode']!=='mixed',fn($q)=>$q->where('type',app(\App\Services\CompanyOperations::class)->current()['catalog_mode']==='services'?'service':'product'))->orderBy('name')->get(),
            'customers'=>Customer::orderBy('name')->get(),
            'currentSalesperson'=>$own,'selectedSalespersonId'=>$selectedId,'selectedCustomerId'=>$data['customer_id']??null,
            'unassignedQuotesCount'=>$actor->role==='admin'?Document::where('type','quote')->whereIn('status',['draft','approved'])->whereNull('salesperson_id')->count():0,
        ]);
    }
}
