<?php

namespace App\Http\Controllers;

use App\Models\SupermarketApproval;
use App\Services\Supermarket;
use App\Services\SupermarketApprovals;
use Illuminate\Http\Request;

final class SupermarketApprovalController extends Controller
{
    public function index(Request $request, Supermarket $market)
    {
        $market->actor($request->attributes->get('currentUser')->id, 'supermarket.supervise');
        $data = $request->validate(['status' => 'nullable|in:pending,approved,rejected,used']);
        return view('supermarket.approvals', ['approvals' => SupermarketApproval::with('cart', 'requester', 'supervisor')
            ->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->latest()->paginate(25)->withQueryString()]);
    }

    public function request(Request $request, string $company, int $cart, SupermarketApprovals $service)
    {
        $service->request($cart, $request->all(), $request->attributes->get('currentUser')->id);
        return redirect()->route('supermarket.show', ['company' => $company, 'cart' => $cart])->with('success', 'Solicitação enviada ao supervisor. A venda deve permanecer sem alterações até a decisão.');
    }

    public function decide(Request $request, string $company, int $approval, SupermarketApprovals $service)
    {
        $data = $request->validate(['decision' => 'required|in:approve,reject', 'reason' => 'required|string|min:3|max:1000']);
        $service->decide($approval, $data['decision'], $data['reason'], $request->attributes->get('currentUser')->id);
        return redirect()->route('supermarket.approvals.index', ['company' => $company])->with('success', 'Decisão registrada.');
    }

    public function apply(Request $request, string $company, int $cart, int $approval, SupermarketApprovals $service)
    {
        $data = $request->validate(['version' => 'required|integer|min:0']);
        $service->apply($cart, $approval, $data['version'], $request->attributes->get('currentUser')->id);
        return redirect()->route('supermarket.show', ['company' => $company, 'cart' => $cart])->with('success', 'Autorização aplicada ao atendimento.');
    }
}
