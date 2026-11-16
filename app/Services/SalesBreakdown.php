<?php
namespace App\Services;
use App\Models\{Sale,Document};
final class SalesBreakdown
{
    public function year(int $year): array
    {
        $sales=Sale::where('status','completed')->whereYear('created_at',$year)->get(['id','total','payment_method','document_id']);
        $labels=app(PaymentMethods::class)->labels('sale_payment_method',$sales->pluck('id')->all());$documents=Document::whereIn('id',$sales->pluck('document_id')->filter())->get(['id','type'])->keyBy('id');
        $methods=[];$origins=[];$total='0.00';
        foreach($sales as $sale){
            $name=$labels[$sale->id]??PaymentMethods::TYPES[$sale->payment_method]??$sale->payment_method;$key=$sale->payment_method.'|'.$name;
            $methods[$key]??=['label'=>$name,'type'=>$sale->payment_method,'total'=>'0.00','count'=>0];$methods[$key]['total']=bcadd($methods[$key]['total'],$sale->total,2);$methods[$key]['count']++;
            $origin=$sale->document_id?($documents->get($sale->document_id)?->type==='work_order'?'Ordem de serviço':'Orçamento aprovado'):'PDV';
            $origins[$origin]??=['label'=>$origin,'total'=>'0.00','count'=>0];$origins[$origin]['total']=bcadd($origins[$origin]['total'],$sale->total,2);$origins[$origin]['count']++;$total=bcadd($total,$sale->total,2);
        }
        $summarize=function(array $rows)use($total){foreach($rows as &$row){$row['percent']=bccomp($total,'0',2)>0?bcmul(bcdiv($row['total'],$total,6),'100',2):'0.00';$row['ticket']=$row['count']?Decimal::round(bcdiv($row['total'],(string)$row['count'],8)):'0.00';}unset($row);uasort($rows,fn($a,$b)=>bccomp($b['total'],$a['total'],2));return array_values($rows);};
        return ['methods'=>$summarize($methods),'origins'=>$summarize($origins),'total'=>$total,'count'=>$sales->count(),'year'=>$year];
    }
}
