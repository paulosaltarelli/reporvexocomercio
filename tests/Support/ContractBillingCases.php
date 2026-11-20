<?php

namespace Tests\Support;

use App\Platform\ContractBilling;
use App\Platform\PlatformAudit;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait ContractBillingCases
{
    public function test_central_contract_clamps_month_end_and_preserves_issued_and_overdue_installments(): void
    {
        $this->travelTo(Carbon::parse('2028-02-01 12:00:00'));
        $service = app(ContractBilling::class);
        $company = $this->company->id;
        $actor = $this->admin->id;
        $terms = ['start_date' => '2028-01-31', 'end_date' => '2028-05-31', 'monthly_amount' => '129.90', 'due_day' => 31];
        $contract = $service->create($company, $terms, $actor, $this->admin->auth_version);
        $this->assertSame([], $contract->restricted_modules);
        $rows = $contract->installments()->orderBy('period')->get();
        $this->assertSame(['2028-01-31', '2028-02-29', '2028-03-31', '2028-04-30', '2028-05-31'],
            $rows->map(fn ($row) => $row->due_date->toDateString())->all());
        $this->assertSame('overdue', $rows[0]->status);
        $this->assertSame(0, $service->generate($company, $contract->id));
        DB::connection('central')->transaction(fn () => $service->lockForCharge($company, $rows[1]->id));
        $revised = array_replace($terms, ['end_date' => '2028-04-30', 'monthly_amount' => '150', 'due_day' => 28, 'expected_version' => 1]);
        $contract = $service->update($company, $contract->id, $revised, $actor, $this->admin->auth_version);
        $this->assertSame(2, $contract->version);
        $this->assertSame('129.90', $rows[0]->fresh()->amount);
        $this->assertSame('2028-02-29', $rows[1]->fresh()->due_date->toDateString());
        $this->assertSame('129.90', $rows[1]->fresh()->amount);
        $this->assertSame('150.00', $rows[2]->fresh()->amount);
        $this->assertSame('2028-03-28', $rows[2]->fresh()->due_date->toDateString());
        $this->assertSame('cancelled', $rows[4]->fresh()->status);
        $this->assertSame('0.00', $rows[4]->fresh()->balance);
        $this->assertSame('129.90', PlatformAudit::where('action', 'contract.updated')->firstOrFail()->changes['before']['monthly_amount']);
        try {
            $service->update($company, $contract->id, $revised, $actor, $this->admin->auth_version);
            $this->fail('A versão desatualizada deveria falhar.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('expected_version', $e->errors());
        }
        $this->expectException(ValidationException::class);
        $service->create($company, array_replace($terms, ['start_date' => '2028-04-30']), $actor, $this->admin->auth_version);
    }

    public function test_grace_periods_control_the_first_due_date_and_the_first_restricted_day(): void
    {
        $this->travelTo(Carbon::parse('2027-04-01 12:00'));
        $billing = app(ContractBilling::class);
        $terms = ['start_date' => '2027-04-01', 'end_date' => '2027-08-31', 'due_day' => 10,
            'monthly_amount' => '100', 'implantation_grace_days' => 90, 'overdue_grace_days' => 5,
            'restricted_modules' => ['sales']];
        $contract = $billing->create($this->company->id, $terms, $this->admin->id, $this->admin->auth_version);
        $this->assertSame('2027-06-30', $contract->billingStartDate()->toDateString());
        $rows = $contract->installments()->orderBy('due_date')->get();
        $this->assertSame(['2027-07-10', '2027-08-10'], $rows->map(fn ($i) => $i->due_date->toDateString())->all());
        $first = $rows->first();
        $this->travelTo(Carbon::parse('2027-06-29 23:59'));
        try {
            DB::connection('central')->transaction(fn () => $billing->lockForCharge($this->company->id, $first->id));
            $this->fail('A cobrança antecipada deveria estar bloqueada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('installment', $exception->errors());
        }
        $this->travelTo(Carbon::parse('2027-06-30 00:00'));
        DB::connection('central')->transaction(fn () => $billing->lockForCharge($this->company->id, $first->id));
        $policy = app(\App\Platform\ContractAccess::class);
        $this->travelTo(Carbon::parse('2027-07-15 23:59'));
        $this->assertSame('overdue', $first->status);
        $this->assertSame([], $policy->restricted($this->company));
        $this->travelTo(Carbon::parse('2027-07-16 00:00'));
        $this->assertArrayHasKey('sales', $policy->restricted($this->company));
        $billing->confirmPix($this->company->id, $first->id, str_repeat('a', 32), 'E'.str_repeat('3', 31), '100.00');
        $this->assertSame([], $policy->restricted($this->company));
    }

    public function test_contract_payment_replays_bank_confirmation_and_rejects_cross_tenant(): void
    {
        $this->travelTo(Carbon::parse('2028-04-30 12:00:00'));
        $service = app(ContractBilling::class);
        $company = $this->company->id;
        $contract = $service->create($company, ['start_date' => '2028-04-30', 'end_date' => '2028-04-30', 'due_day' => 31, 'monthly_amount' => '120'],
            $this->admin->id, $this->admin->auth_version);
        $row = $contract->installments()->sole();
        $this->assertSame('pending', $row->status);
        DB::connection('central')->transaction(fn () => $service->lockForCharge($company, $row->id));
        $txid = str_repeat('a', 32);
        $e2e = 'E'.str_repeat('1', 31);
        $this->assertSame('partial', $service->confirmPix($company, $row->id, $txid, $e2e, '20.00')->status);
        $this->assertSame('100.00', $service->confirmPix($company, $row->id, $txid, $e2e, '20')->balance);
        $this->assertSame('paid', $service->confirmPix($company, $row->id, str_repeat('b', 32), 'E'.str_repeat('2', 31), '100.00')->status);
        $this->assertCount(2, $row->payments()->get());
        $this->assertSame('0.00', $row->fresh()->balance);
        try {
            $service->confirmPix($company, $row->id, $txid, $e2e, '21.00');
            $this->fail('Confirmação divergente deveria falhar.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment',$e->errors());
        }
        $this->expectException(ModelNotFoundException::class);
        $service->confirmPix($company + 1,$row->id,$txid,$e2e,'20');
    }
}
