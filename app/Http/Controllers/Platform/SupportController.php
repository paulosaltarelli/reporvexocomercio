<?php
namespace App\Http\Controllers\Platform;
use App\Http\Controllers\Controller;
use App\Platform\SupportAccess;
use Illuminate\Http\Request;
class SupportController extends Controller
{
    public function enter(Request $r,int $id,SupportAccess $support){$slug=$support->enter($r,$id);return redirect()->route('dashboard',['company'=>$slug]);}
    public function leave(Request $r,SupportAccess $support){$support->leave($r);return redirect()->route('platform.index')->with('success','Acesso de suporte encerrado.');}
}
