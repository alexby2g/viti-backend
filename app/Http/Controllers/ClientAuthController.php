<?php

namespace App\Http\Controllers;

use App\Models\{Cliente, Empresa, SolicitudSistema, Usuario};
use App\Support\{Audit, Code};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClientAuthController extends Controller
{
    /**
     * Completa el alta de una cuenta creada mediante Google.
     * El token es de un solo uso y expira rápidamente; no permite registro anónimo.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required','string','size:64'],
            'nombre' => ['required','string','max:180'],
            'ciudad' => ['nullable','string','max:100'],
            'whatsapp' => ['nullable','string','max:30'],
            'sistema_nombre' => ['required','string','max:180'],
            'sistema_que_hara' => ['required','string','max:3000'],
            'sistema_publico' => ['required','string','max:1000'],
            'sistema_vision' => ['required','string','max:3000'],
        ]);

        $userId = Cache::pull('viti:google:onboarding:'.$data['token']);
        abort_unless($userId, 410, 'El enlace de onboarding expiró. Inicia nuevamente con Google.');

        $usuario = Usuario::query()->where('id',$userId)->where('rol','cliente')->where('estado','activo')->firstOrFail();
        abort_if($usuario->cliente_id, 409, 'La cuenta ya tiene un perfil de cliente configurado.');

        [$cliente, $empresa, $solicitud] = DB::transaction(function () use ($data, $usuario): array {
            $cliente = Cliente::create([
                'nombre' => trim($data['nombre']),
                'telefono' => null,
                'whatsapp' => $data['whatsapp'] ?? null,
                'correo' => $usuario->correo,
                'ciudad' => $data['ciudad'] ?? null,
                'estado' => 'activo',
                'canal_origen' => 'google',
            ]);

            $empresa = Empresa::create([
                'cliente_id' => $cliente->id,
                'codigo' => Code::next('empresas','EMP'),
                'nombre_comercial' => trim($data['sistema_nombre']),
                'actividad' => Str::limit(trim($data['sistema_que_hara']), 200, ''),
                'ciudad' => $data['ciudad'] ?? null,
                'estado' => 'prospecto',
            ]);

            $solicitud = SolicitudSistema::create([
                'empresa_id' => $empresa->id,
                'cliente_id' => $cliente->id,
                'codigo' => Code::next('solicitudes_sistema','SOL'),
                'public_token' => Str::random(48),
                'publico_habilitado' => true,
                'titulo' => trim($data['sistema_nombre']),
                'resumen' => trim("Qué hará: {$data['sistema_que_hara']}\nPúblico: {$data['sistema_publico']}\nVisión: {$data['sistema_vision']}"),
                'estado' => 'borrador',
                'prioridad' => 'normal',
                'acuerdo_comercial_requerido' => true,
            ]);

            $usuario->update(['cliente_id' => $cliente->id, 'nombre' => trim($data['nombre'])]);

            return [$cliente, $empresa, $solicitud];
        });

        Audit::log($request, 'cliente_google_onboarding_completado', $cliente, 'El cliente completó su onboarding inicial y creó su primera solicitud VITI.');
        Audit::log($request, 'solicitud_cliente_iniciada', $solicitud, 'Se creó la primera solicitud VITI desde el onboarding de Google.');

        return response()->json([
            'message' => 'Cuenta de cliente configurada correctamente.',
            'data' => [
                'usuario' => $usuario->fresh()->loadMissing('cliente'),
                'cliente' => $cliente->fresh()->load('empresas'),
                'empresa' => $empresa,
                'solicitud' => $solicitud,
            ],
        ],201);
    }
}
