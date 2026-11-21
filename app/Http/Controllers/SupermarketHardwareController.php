<?php

namespace App\Http\Controllers;

use App\Services\DeviceSettings;
use App\Services\ManualWeightInput;
use App\Services\Supermarket;
use Illuminate\Http\Request;

final class SupermarketHardwareController extends Controller
{
    public function index(Request $request, Supermarket $market)
    {
        $market->actor($request->attributes->get('currentUser')->id, 'supermarket.supervise');
        return response()->json(['settings' => app(DeviceSettings::class)->current(), 'capabilities' => [
            'barcode_reader' => ['available' => true, 'method' => 'keyboard', 'message' => 'Leitores que enviam o código como teclado usam o campo de leitura.'],
            'weight_input' => ['available' => true, 'method' => 'manual', 'message' => 'Peso informado pelo operador ou por etiqueta pesável configurada.'],
            'receipt_printing' => ['available' => true, 'method' => 'browser', 'message' => 'Impressão pela janela do navegador, em 58 ou 80 mm.'],
            'direct_scale' => ['available' => false, 'message' => 'A leitura direta depende da identificação da balança e da instalação do adaptador compatível.'],
            'tef' => ['available' => false, 'message' => 'A confirmação automática de cartão depende de contratação e homologação com a adquirente.'],
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function test(Request $request, Supermarket $market, ManualWeightInput $weight)
    {
        $market->actor($request->attributes->get('currentUser')->id, 'supermarket.supervise');
        $data = $request->validate(['code' => 'nullable|required_without:manual_weight|string|max:80',
            'manual_weight' => 'nullable|required_without:code|string|max:24']);
        $result = ['read_only' => true];
        if (!empty($data['code'])) {
            $scan = $market->scan($data['code']);
            $result['product'] = $scan['product']->only(['id', 'name', 'sku', 'unit', 'sale_price']);
            $result['scanned_quantity'] = $scan['quantity'];
            $result['weighted_label'] = $scan['label'];
        }
        if (isset($data['manual_weight']) && $data['manual_weight'] !== '') {
            $result['manual_weight'] = $weight->quantity($data['manual_weight']);
        }
        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
