<?php
namespace App\Http\Controllers;
use App\Services\FitFamilyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class FitFamilyPagoController extends Controller {
 public function index(Request $r,FitFamilyContext $c):JsonResponse{$e=$c->resolve($r);$c->assertAdmin($r,$e);return response()->json(['data'=>DB::table('fitfamily_pagos as pg')->join('fitfamily_pedidos as p','p.id','=','pg.pedido_id')->join('clientes as cl','cl.id','=','p.cliente_id')->where('p.empresa_id',$e->id)->orderByDesc('pg.id')->get(['pg.*','p.numero as pedido_numero','p.estado as pedido_estado','cl.nombre as cliente_nombre'])]);}
 public function update(Request $r,int $id,FitFamilyContext $c):JsonResponse{$e=$c->resolve($r);$c->assertAdmin($r,$e);$d=$r->validate(['estado'=>['required',Rule::in(['pendiente','verificado','rechazado'])],'referencia'=>'nullable|string|max:160']);$ok=DB::table('fitfamily_pagos as pg')->join('fitfamily_pedidos as p','p.id','=','pg.pedido_id')->where('pg.id',$id)->where('p.empresa_id',$e->id)->exists();abort_unless($ok,404);DB::table('fitfamily_pagos')->where('id',$id)->update($d+['updated_at'=>now()]);return response()->json(['message'=>'Pago actualizado.','data'=>DB::table('fitfamily_pagos')->find($id)]);}
}
