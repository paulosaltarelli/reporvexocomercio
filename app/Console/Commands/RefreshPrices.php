<?php
namespace App\Console\Commands;
use App\Models\{Product,Tenant,User};
use App\Services\{TenantConnection,PricingService};
use Illuminate\Console\Command;
class RefreshPrices extends Command {
 protected $signature='vendra:prices-sync {company}';
 protected $description='Confere alertas e aplica regras de preço apenas nos produtos configurados em modo automático.';
 public function handle(TenantConnection $connection):int{
  $tenant=Tenant::where('slug',$this->argument('company'))->where('active',true)->firstOrFail();
  try{$connection->connect($tenant);$user=User::where('active',true)->where('role','admin')->orderBy('id')->firstOrFail();$count=0;foreach(Product::orderBy('id')->pluck('id') as $id){app(PricingService::class)->syncProduct($id,$user->id,'rules_refresh');$count++;}$this->info('Regras conferidas em '.$count.' produtos.');return self::SUCCESS;}finally{$connection->disconnect();}
 }
}
