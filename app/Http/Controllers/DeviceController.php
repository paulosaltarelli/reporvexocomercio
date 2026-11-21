<?php

namespace App\Http\Controllers;

use App\Models\CompanyOperation;
use App\Services\{DeviceSettings, InstallerAccess};
use Illuminate\Http\Request;

final class DeviceController extends Controller
{
    public function index(Request $request, DeviceSettings $settings)
    {
        abort_unless(InstallerAccess::adminActor($request->attributes->get('currentUser')->id),403);
        return view('devices.index',['deviceSettings'=>$settings->current(),'deviceVersion'=>CompanyOperation::find(1)?->version??0]);
    }

    public function save(Request $request, DeviceSettings $settings)
    {
        $settings->save($request->all(),$request->attributes->get('currentUser')->id);
        return redirect()->route('devices.index',['company'=>$request->route('company')])->with('success','Dispositivos e impressão configurados.');
    }

    public function printTest(Request $request)
    {
        abort_unless(InstallerAccess::adminActor($request->attributes->get('currentUser')->id),403);
        return response()->view('devices.print-test',['receiptSale'=>false,'receiptBackUrl'=>route('devices.index',['company'=>$request->route('company')])])
            ->header('Cache-Control','private, no-store');
    }
}
