<?php

namespace App\Http\Controllers;

use App\Access\AccessProfiles;
use App\Pix\TitleCharge;
use Illuminate\Http\Request;
use Com\Tecnick\Barcode\Barcode;

class SicoobPixController extends Controller
{
    public function sync(Request $request, string $company, int $id, string $operation, TitleCharge $charges)
    {
        app(AccessProfiles::class)->authorize($request);
        $charge = $charges->sync($request->attributes->get('tenant'), $id,
            $request->attributes->get('currentUser')->id, $operation === 'gerar');
        $image = null;
        if (isset($charge['code'])) {
            $qr = (new Barcode)->getBarcodeObj('QRCODE,M', $charge['code'], -4, -4, 'black', [16, 16, 16, 16]);
            $qr->setBackgroundColor('white');
            $image = base64_encode($qr->getPngData(false));
        }
        return response()->view('finance-pix', compact('charge', 'image', 'id'))->header('Cache-Control', 'private, no-store');
    }
}
