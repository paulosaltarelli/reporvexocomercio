<?php

namespace Tests\Support;

use App\Access\AccessProfiles;
use App\Models\{CompanyOperation, User};
use App\Services\{CompanyOperations, DeviceSettings, TenantConnection};
use Illuminate\Support\Facades\DB;

trait OperationalCompletionCases
{
    private function completionActor(string $name, array $permissions, string $limit='100'): User
    {
        $profiles=app(AccessProfiles::class);
        $profile=$profiles->save(['version'=>$profiles->state(true)['version'],'name'=>$name,'permissions'=>$permissions,'max_discount_percent'=>$limit],$this->user->id);
        return DB::connection('tenant')->transaction(function () use ($profiles,$profile,$name) {
            $user=User::create(['name'=>$name,'email'=>strtolower(str_replace(' ','-',$name)).'@example.test','password'=>'completion-test-123','role'=>'operator','active'=>true]);
            $profiles->assign($user->id,'operator',$profile,$this->user->id);
            return $user;
        });
    }

    public function test_operational_completion_activity_revisions_are_read_only_and_separate_kitchen_from_salon(): void
    {
        app(CompanyOperations::class)->save($this->operationPayload(['inventory'=>false,'segment'=>'restaurant','operation_modes'=>['restaurant'],'default_operation'=>'restaurant']),$this->user->id);
        $cook=$this->completionActor('Cozinha activity',['restaurant.kitchen']);
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$cook->id,'auth_version'=>0]);
        $activity=$this->getJson('/app/a/restaurante/atividade?view=production')->assertOk()->assertJsonStructure(['version','updated_at']);
        $this->assertSame(64,strlen($activity->json('version')));
        $this->getJson('/app/a/restaurante/atividade?view=production')->assertJsonPath('version',$activity->json('version'));
        $this->getJson('/app/a/restaurante/atividade?view=index')->assertForbidden();
        $this->getJson('/app/a/restaurante/atividade?view=order&order_id=999')->assertForbidden();
        $this->getJson('/app/a/devices')->assertForbidden();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(0,\App\Models\Sale::count());
        $this->assertSame(0,\App\Models\StockMovement::count());
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id,'auth_version'=>0]);
        $this->get('/app/a/restaurante')->assertOk()->assertViewHas('activityState');
        $initial=$this->getJson('/app/a/restaurante/atividade?view=index')->assertOk()->json('version');
        app(TenantConnection::class)->connect($this->a);
        $operations=app(\App\Services\RestaurantOperations::class);
        $table=$operations->createTable(['name'=>'Mesa activity'],$this->user->id);
        $updated=$this->getJson('/app/a/restaurante/atividade?view=index')->assertOk()->json('version');
        $this->assertNotSame($initial,$updated);
        app(TenantConnection::class)->connect($this->a);
        $order=$operations->open(['request_key'=>(string)\Illuminate\Support\Str::uuid(),'table_id'=>$table->id],$this->user->id);
        $screen=$this->get('/app/a/restaurante/comandas/'.$order->id)->assertOk();
        $baseline=$screen->viewData('activityState')['version'];
        $this->getJson('/app/a/restaurante/atividade?view=order&order_id='.$order->id)->assertOk()->assertJsonPath('version',$baseline);
        app(TenantConnection::class)->connect($this->a);
        $operations->addItem($order->id,['request_key'=>(string)\Illuminate\Support\Str::uuid(),'version'=>$order->version,'product_id'=>$this->product->id,'quantity'=>'1','station'=>'kitchen'],$this->user->id);
        $next=$this->getJson('/app/a/restaurante/atividade?view=order&order_id='.$order->id)->assertOk()->json('version');
        $this->assertNotSame($baseline,$next);
    }

    public function test_operational_completion_devices_preserve_company_configuration_and_print_only_a_test(): void
    {
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id,'auth_version'=>0]);
        $settings=app(DeviceSettings::class);
        $this->assertSame(80,$settings->current()['receipt_width']);
        $before=CompanyOperation::find(1)?->configuration??CompanyOperations::DEFAULTS;
        $this->post('/app/a/devices',['version'=>CompanyOperation::find(1)?->version??0,'receipt_width'=>58,'reader'=>'keyboard','weight_input'=>'manual','printer_label'=>'Epson do caixa'])->assertRedirect('/app/a/devices')->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(58,$settings->current()['receipt_width']);
        $after=CompanyOperation::findOrFail(1)->configuration;
        unset($after['_device_settings']);
        $this->assertEquals($before,$after);
        $this->get('/app/a/devices/print-test')->assertOk()->assertSee('TESTE DE IMPRESSÃO')->assertSee('data-roll="58"',false);
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(0,\App\Models\Sale::count());
        $this->assertSame(0,\App\Models\StockMovement::count());
        $this->postJson('/app/a/devices',['version'=>CompanyOperation::find(1)->version,'receipt_width'=>72,'reader'=>'keyboard','weight_input'=>'serial'])->assertUnprocessable();
    }

    public function test_operational_completion_profile_supervisor_ceiling_and_checkout_dependencies_are_effective(): void
    {
        $supervisor=$this->completionActor('Supervisor limitado',['supermarket.supervise'],'7.50');
        $this->assertSame('7.50',app(AccessProfiles::class)->discountLimit($supervisor));
        $cashier=$this->completionActor('Caixa restaurante parcial',['restaurant.checkout']);
        $this->assertTrue($cashier->allows('restaurant.receive'));
        $this->assertFalse($cashier->allows('restaurant.refund'));
        $this->assertFalse($cashier->allows('administration'));
        $this->assertSame('0.00',app(AccessProfiles::class)->discountLimit($cashier));
    }
    public function test_operational_completion_cannot_disable_inventory_during_an_open_recipe_order(): void
    {
        app(CompanyOperations::class)->save($this->operationPayload(['inventory'=>true,'segment'=>'restaurant','operation_modes'=>['restaurant'],'default_operation'=>'restaurant']),$this->user->id);
        $ingredient=$this->product->replicate();
        $ingredient->fill(['name'=>'Ingrediente preparado','sku'=>'COMPLETION-ING','unit'=>'UN','controls_stock'=>true])->save();
        $this->product->update(['controls_stock'=>false]);
        app(\App\Services\RestaurantCatalog::class)->save($this->product->id,['version'=>0,'station'=>'kitchen','stock_mode'=>'recipe','recipe'=>['ingredients'=>[['product_id'=>$ingredient->id,'quantity'=>'1']]],'groups'=>[]],$this->user->id);
        $operations=app(\App\Services\RestaurantOperations::class);
        $table=$operations->createTable(['name'=>'Mesa com ingredientes'],$this->user->id);
        $order=$operations->open(['request_key'=>(string)\Illuminate\Support\Str::uuid(),'table_id'=>$table->id],$this->user->id);
        $operations->addItem($order->id,['request_key'=>(string)\Illuminate\Support\Str::uuid(),'version'=>$order->version,'product_id'=>$this->product->id,'quantity'=>'1','station'=>'kitchen'],$this->user->id);
        try {
            app(CompanyOperations::class)->save($this->operationPayload(['version'=>CompanyOperation::findOrFail(1)->version,'inventory'=>false,'segment'=>'restaurant','operation_modes'=>['restaurant'],'default_operation'=>'restaurant']),$this->user->id);
            $this->fail('A comanda aberta perdeu a gestão dos ingredientes.');
        } catch (\Illuminate\Validation\ValidationException $error) { $this->assertArrayHasKey('inventory',$error->errors()); }
        $this->assertTrue(app(CompanyOperations::class)->current()['inventory']);
        $this->assertSame(0,\App\Models\Sale::count());
        $this->assertSame(0,\App\Models\StockMovement::count());
    }

}
