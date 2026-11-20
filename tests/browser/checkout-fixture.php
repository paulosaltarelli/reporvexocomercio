<?php

/** Render the real forms for the Chromium checkout regression tests, without a database. */
use App\Models\Product;
use App\Services\CardFees;
use App\Services\PaymentMethods;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['session.driver'=>'array', 'app.key'=>'base64:'.base64_encode(random_bytes(32))]);
request()->setLaravelSession(session()->driver());
$app->instance(PaymentMethods::class, new class {
    public function options(): array
    {
        return array_combine(['cash','credit','pix'], array_map(fn($type)=>['type'=>$type,'label'=>$type], ['cash','credit','pix']));
    }
});
$app->instance(CardFees::class, new class {
    public function options(): array
    {
        return ['visa'=>['name'=>'Visa','type'=>'credit','policy'=>'pass','rates'=>[1=>'5.00',2=>'5.00']]];
    }
});
$currentUser = new class { public function allows(string $permission): bool { return false; } };
$currentSalesperson = (object)['id'=>1,'name'=>'Vendedor de teste','active'=>true];
$tenant = (object)['slug'=>'demo'];
$document = (object)['id'=>1, 'total'=>'95.00', 'seller_name'=>'Vendedor de teste'];
$sellers = collect([$currentSalesperson]);
$customers = collect();
$operationConfig = [];
$errors = new ViewErrorBag;
$products = collect([1,2])->map(fn($id)=>(new Product)->forceFill(['id'=>$id,'name'=>'Peça '.$id,'sku'=>'SKU-'.$id,'barcode'=>'COD-'.$id,'type'=>'product','unit'=>'UN','sale_price'=>'95.00','controls_stock'=>false,'discount_enabled'=>false,'max_discount_percent'=>'0']));
$dueDate=now()->addMonthNoOverflow()->toDateString();
$single=['payment_option'=>'credit','paid'=>'0','installments'=>'2','due_date'=>$dueDate,'card_brand'=>'visa','card_installments'=>'2','fee_policy'=>'pass','rate_seen'=>'5.00','charged_seen'=>'100.00','fee_reviewed'=>'1'];
$split=['payments'=>[
    ['payment_option'=>'cash','paid'=>'1','amount'=>'19.00','cash_received'=>'20.00'],
    array_merge($single,['amount'=>'76.00','charged_seen'=>'80.00'])
]];
$mode=$argv[1]??'pos';
if ($mode==='document') session()->flashInput($single);
if ($mode==='split') session()->flashInput($split);
if ($mode==='stale') session()->flashInput(array_merge($single,['rate_seen'=>'3.00']));
$component=match($mode){
    'pos'=>'components.sales.checkout-form',
    'items'=>'components.sales.entry-items',
    default=>'documents.checkout',
};
if ($mode==='items') session()->flashInput(['items_present'=>1,'items'=>[3=>['kind'=>'product','id'=>2,'quantity'=>'3','unit_price'=>'95.00']]]);
echo '<meta name="expected-due-date" content="'.$dueDate.'">';
echo Blade::render("@include(\$component) @stack('scripts')",compact('component','products','currentUser','currentSalesperson','tenant','sellers','customers','operationConfig','errors','document'));
