<?php
namespace App\Http\Controllers;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
class FitFamilyAuthController extends Controller {
 public function login(Request $request):JsonResponse {
  $d=$request->validate(['email'=>'required|string|max:190','password'=>'required|string']);$identifier=trim($d['email']);
  $u=Usuario::query()->where('estado','activo')->where(fn($q)=>$q->whereRaw('LOWER(correo)=?',[Str::lower($identifier)])->orWhereRaw('LOWER(usuario)=?',[Str::lower($identifier)]))->first();
  if(!$u||!Hash::check($d['password'],$u->password)) return response()->json(['message'=>'Credenciales inválidas.'],401);
  $token=$u->createToken('fitfamily-web')->plainTextToken;
  return response()->json(['message'=>'Autenticación correcta.','token'=>$token,'user'=>['id'=>$u->id,'nombre'=>trim($u->nombre.' '.($u->apellido??'')),'email'=>$u->correo,'rol'=>$u->rol]]);
 }
 public function me(Request $request):JsonResponse{$u=$request->user();return response()->json(['user'=>['id'=>$u->id,'nombre'=>trim($u->nombre.' '.($u->apellido??'')),'email'=>$u->correo,'rol'=>$u->rol,'activo'=>$u->estado==='activo']]);}
 public function logout(Request $request):JsonResponse{$request->user()?->currentAccessToken()?->delete();return response()->json(['message'=>'Sesión cerrada.']);}
}
