<?php
namespace App\Services;
use App\Models\Empresa;
use Illuminate\Http\Request;
class FitFamilyContext {
 public function resolve(Request $request): Empresa {
  $empresaId=$request->header('X-FitFamily-Empresa')?:config('fitfamily.empresa_id');
  if(!$empresaId&&$request->user()) $empresaId=$request->user()->negocios()->whereHas('aplicaciones',fn($q)=>$q->whereHas('catalogo',fn($c)=>$c->where('clave',config('fitfamily.catalog_key'))))->value('empresas.id');
  abort_unless($empresaId,503,'FitFamily todavía no tiene una empresa configurada.');
  $empresa=Empresa::query()->find($empresaId);abort_unless($empresa,404,'La empresa de FitFamily no existe.');
  abort_unless($empresa->aplicaciones()->whereHas('catalogo',fn($q)=>$q->where('clave',config('fitfamily.catalog_key')))->exists(),404,'La aplicación FitFamily no está asignada a esta empresa.');
  return $empresa;
 }
 public function assertAdmin(Request $request,Empresa $empresa):void {
  $user=$request->user();abort_unless($user,401,'Unauthenticated.');abort_unless($user->isPlatformAdmin(),403,'Solo un administrador puede realizar esta acción.');
  if(!$user->isSuperAdmin()) abort_unless($user->negocios()->whereKey($empresa->id)->wherePivot('activo',true)->exists(),403,'No tienes acceso administrativo a esta empresa.');
 }
}
