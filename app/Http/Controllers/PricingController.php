<?php
namespace App\Http\Controllers;
use App\Models\{PriceReview,PriceChange,Product};
use App\Services\PricingService;
use Illuminate\Http\Request;
class PricingController extends Controller {
 private function admin(Request $r):void{abort_unless($r->attributes->get('currentUser')->role==='admin',403);}
 public function index(Request $r){
  $this->admin($r);$filter=$r->query('filter','pending');abort_unless(in_array($filter,['pending','all']),422);
  $reviews=PriceReview::with('product.group')->when($filter==='pending',fn($q)=>$q->where('needs_attention',true))->orderByDesc('needs_attention')->latest('updated_at')->paginate(20)->withQueryString();
  return view('prices',['reviews'=>$reviews,'changes'=>PriceChange::with('product','user')->latest()->limit(30)->get(),'pendingCount'=>PriceReview::where('needs_attention',true)->count(),'filter'=>$filter]);
 }
 public function apply(Request $r,string $company,int $id){
  $this->admin($r);$d=$r->validate(['request_key'=>'required|uuid','expected_price'=>'required|numeric|min:0|max:999999999999.99|decimal:0,2']);
  app(PricingService::class)->applySuggested($id,$r->attributes->get('currentUser')->id,(string)$d['expected_price'],$d['request_key']);
  return back()->with('success','Preço atualizado conforme a sugestão revisada. Alteração registrada no histórico.');
 }
 public function refresh(Request $r){
  $this->admin($r);$uid=$r->attributes->get('currentUser')->id;
  foreach(Product::orderBy('id')->pluck('id') as $id)app(PricingService::class)->syncProduct($id,$uid,'admin_refresh');
  return back()->with('success','Regras e alertas conferidos. Produtos automáticos seguem a configuração atual.');
 }
}
