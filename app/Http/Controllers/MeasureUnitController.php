<?php

namespace App\Http\Controllers;

use App\Models\{CompanyOperation, Product};
use App\Services\{CompanyOperations, Decimal, MeasureUnits};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MeasureUnitController extends Controller
{
    public function index(Request $request)
    {
        app(\App\Access\AccessProfiles::class)->authorize($request);
        return view('products.units');
    }

    public function store(Request $request, string $company, ?int $id = null)
    {
        app(\App\Access\AccessProfiles::class)->authorize($request);
        $rules = ['code'=>['required','string','regex:/^[A-Z][A-Z0-9]{0,9}$/D']];
        $data = $request->validate($rules + ($id === null
            ? ['label'=>'required|string|max:60']
            : ['base'=>'required|string|max:10','factor'=>'required|decimal:0,6','purpose'=>'required|in:purchase,sale,both,remove']));
        DB::connection('tenant')->transaction(function () use ($id, $data, $request) {
            CompanyOperation::firstOrCreate(['id'=>1], ['configuration'=>CompanyOperations::DEFAULTS]);
            $row = CompanyOperation::whereKey(1)->lockForUpdate()->firstOrFail();
            $config = $row->configuration;
            $code = $data['code'];
            $catalog = MeasureUnits::catalog($config);
            if ($id === null) {
                if (isset($catalog[$code])) throw ValidationException::withMessages(['code'=>'Esta sigla já está cadastrada.']);
                $config['measure_units'][$code] = $data['label'];
            } else {
                $product = Product::lockForUpdate()->findOrFail($id);
                if ($product->type !== 'product' || $product->unit !== $data['base'] || $code === $product->unit || !isset($catalog[$code])) {
                    throw ValidationException::withMessages(['code'=>'Selecione outra unidade cadastrada e confira a unidade de estoque.']);
                }
                $factor = Decimal::input((string)$data['factor'], 6, 'factor', '0.000001', '999999999');
                $links = $config['unit_links'][$id] ?? ['units'=>[]];
                $links['base'] = $product->unit;
                if ($data['purpose'] === 'remove') unset($links['units'][$code]);
                else $links['units'][$code] = ['factor'=>$factor,'sale'=>$data['purpose'] !== 'purchase','purchase'=>$data['purpose'] !== 'sale'];
                $config['unit_links'][$id] = $links;
            }
            $row->update(['configuration'=>$config,'version'=>$row->version+1,'updated_by'=>$request->attributes->get('currentUser')->id]);
        }, 3);
        return back()->with('success', $id === null ? 'Unidade cadastrada.' : 'Equivalência atualizada.');
    }
}
