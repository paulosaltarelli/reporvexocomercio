<?php

namespace App\Services;

use App\Models\{CompanyOperation, OperationRecord};
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;

final class DeviceSettings
{
    private const KEY = '_device_settings';
    public const DEFAULTS = ['receipt_width'=>80,'reader'=>'keyboard','weight_input'=>'manual','printer_label'=>null];

    public function current(): array
    {
        $stored = CompanyOperation::find(1)?->configuration[self::KEY] ?? [];
        $state = array_replace(self::DEFAULTS, is_array($stored) ? array_intersect_key($stored,self::DEFAULTS) : []);
        if (!in_array($state['receipt_width'],[58,80],true)) $state['receipt_width']=80;
        if (!in_array($state['reader'],['keyboard','manual'],true)) $state['reader']='keyboard';
        $state['weight_input']='manual';
        return $state;
    }

    public function save(array $input, int $actor): void
    {
        abort_unless(InstallerAccess::adminActor($actor),403);
        $data = Validator::make($input,['version'=>'required|integer|min:0','receipt_width'=>'required|integer|in:58,80',
            'reader'=>'required|in:keyboard,manual','weight_input'=>'required|in:manual','printer_label'=>'nullable|string|max:100'])->validate();
        DB::connection('tenant')->transaction(function () use ($data,$actor) {
            CompanyOperation::firstOrCreate(['id'=>1],['configuration'=>CompanyOperations::DEFAULTS]);
            $row=CompanyOperation::whereKey(1)->lockForUpdate()->firstOrFail();
            if ($row->version!==(int)$data['version']) throw ValidationException::withMessages(['version'=>'As configurações mudaram. Recarregue antes de salvar.']);
            $before=$this->current();
            $after=['receipt_width'=>(int)$data['receipt_width'],'reader'=>$data['reader'],'weight_input'=>'manual',
                'printer_label'=>filled($data['printer_label']??null)?trim($data['printer_label']):null];
            if ($before===$after) return;
            $configuration=$row->configuration;$configuration[self::KEY]=$after;
            $row->update(['configuration'=>$configuration,'version'=>$row->version+1,'updated_by'=>$actor]);
            OperationRecord::create(['action'=>'device_settings','version'=>$row->version,'user_id'=>$actor,
                'before'=>$before,'after'=>$after,'created_at'=>now()]);
        },3);
    }
}
