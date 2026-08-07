<?php
namespace App\Http\Controllers;
use App\Models\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class AuditoriaController extends Controller
{
 public function index(Request $r):JsonResponse{$q=Auditoria::with('usuario:id,nombre,apellido')->latest();if($r->filled('accion'))$q->where('accion',$r->string('accion'));return response()->json($q->paginate(50));}
}
