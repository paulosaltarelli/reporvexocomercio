<?php

/** Fresh disposable fixture for supermarket_keyboard.py. Both database names are reserved for this test. */
use App\Access\AccessProfiles;
use App\Models\CompanyOperation;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Seller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CompanyOperations;
use App\Services\TenantConnection;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$central = DB::connection('central');
$secret = env('KEYBOARD_QA_PASSWORD');
if (!$app->environment('testing') || !$secret || $central->selectOne('SELECT DATABASE() AS db')->db !== 'vendra_test_keyboard') {
    throw new RuntimeException('Use APP_ENV=testing, KEYBOARD_QA_PASSWORD e o banco dedicado vendra_test_keyboard.');
}
Artisan::call('migrate:fresh', ['--database'=>'central', '--force'=>true]);
$tenant = Tenant::create([
    'slug'=>'keyboard', 'name'=>'Supermercado de teste do teclado', 'active'=>true,
    'db_host'=>$central->getConfig('host'), 'db_name'=>'vendra_t_test_keyboard',
    'db_username'=>$central->getConfig('username'), 'db_password'=>$central->getConfig('password'),
]);
app(TenantConnection::class)->connect($tenant);
if (DB::connection('tenant')->selectOne('SELECT DATABASE() AS db')->db !== 'vendra_t_test_keyboard') {
    throw new RuntimeException('Loja de teste do teclado incorreta.');
}
Artisan::call('migrate:fresh', ['--database'=>'tenant', '--path'=>'database/tenant-migrations', '--force'=>true]);
$admin = User::create(['name'=>'Administrador de teste', 'email'=>'keyboard-admin@example.test', 'password'=>$secret, 'role'=>'admin']);
$cashier = User::create(['name'=>'Operador de teste', 'email'=>'keyboard@example.test', 'password'=>$secret, 'role'=>'operator']);
Seller::firstOrCreate(['user_id'=>$cashier->id], ['name'=>$cashier->name, 'active'=>true]);
app(CompanyOperations::class)->save(array_replace(CompanyOperations::DEFAULTS, [
    'segment'=>'supermarket', 'inventory'=>false, 'operation_modes'=>['supermarket'], 'default_operation'=>'supermarket', 'version'=>0,
]), $admin->id);
$profiles = app(AccessProfiles::class);
$profile = $profiles->save(AccessProfiles::PRESETS['supermarket_cashier'] + ['version'=>CompanyOperation::findOrFail(1)->version], $admin->id);
DB::connection('tenant')->transaction(fn () => $profiles->assign($cashier->id, 'operator', $profile, $admin->id));
$group = ProductGroup::create(['name'=>'Mercearia', 'margin'=>'20.00']);
foreach ([['Arroz agulhinha','ARR-A','7891234567007','10.00'], ['Arroz integral','ARR-I','7891234567014','15.00']] as [$name, $sku, $barcode, $price]) {
    Product::create(['name'=>$name, 'sku'=>$sku, 'barcode'=>$barcode, 'product_group_id'=>$group->id, 'sale_price'=>$price, 'unit'=>'UN']);
}
echo "Loja keyboard pronta.\n";
