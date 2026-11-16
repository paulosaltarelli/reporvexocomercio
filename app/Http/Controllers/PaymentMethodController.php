<?php
namespace App\Http\Controllers;
use App\Models\CompanyOperation;
use App\Services\PaymentMethods;
use Illuminate\Http\Request;
class PaymentMethodController extends Controller
{
    public function index(Request $r,PaymentMethods $service){return view('payment-methods',['methods'=>$service->all(),'version'=>CompanyOperation::find(1)?->version??0]);}
    public function save(Request $r,string $company,PaymentMethods $service){$service->save($r->except('_token'),(int)$r->attributes->get('currentUser')->id);return redirect()->route('payment.methods',['company'=>$company])->with('success','Forma de pagamento salva. Os registros anteriores preservam o nome utilizado na operação.');}
}
