<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ElectrofrioCustomerAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['acceso'=>['required','string','max:160'],'password'=>['required','string']]);
        $access = trim($data['acceso']);
        $digits = preg_replace('/\D+/', '', $access);
        $numeric = preg_match('/^[0-9\s()+.-]+$/', $access) && strlen($digits) >= 5 ? $digits : null;
        $user = Usuario::query()->where('estado','activo')->where('rol','cliente_negocio')
            ->where(function ($query) use ($access, $numeric): void {
                $query->whereRaw('LOWER(usuario) = ?', [Str::lower($access)]);
                if ($numeric) $query->orWhere('telefono',$numeric)->orWhere('documento',$numeric);
            })->with('electrofrioCliente.empresa')->first();

        if (!$user || !$user->electrofrioCliente?->activo || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message'=>'El usuario, teléfono, CI o contraseña no son correctos.'],422);
        }

        auth()->login($user, false);
        $request->session()->regenerate();
        $user->forceFill(['ultimo_acceso'=>now()])->save();
        Audit::log($request,'inicio_sesion_cliente_electrofrio',$user,'Un cliente final inició sesión en Electrofrío.');
        return response()->json(['usuario'=>$user]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['usuario'=>$request->user()->load('electrofrioCliente.empresa')]);
    }
}
