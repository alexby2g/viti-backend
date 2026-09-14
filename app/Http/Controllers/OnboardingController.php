<?php

namespace App\Http\Controllers;

use App\Models\{AlertaSaas,Cliente,Empresa,InvitacionCliente,SolicitudSistema,Usuario};
use App\Services\AccessInvitationService;
use App\Support\{Audit,FirebasePush};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class OnboardingController extends Controller
{
    public function createInvitation(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Las invitaciones genéricas están deshabilitadas. El acceso solo se habilita desde una solicitud VITI aprobada.',
        ], 410);
    }

    public function createSolicitudInvitation(Request $request, SolicitudSistema $solicitud, AccessInvitationService $service): JsonResponse
    {
        $data = $request->validate([
            'dias_vigencia' => ['nullable','integer','min:1','max:30'],
        ]);

        $snapshot = $service->createAndSend($solicitud, $request->user(), (int) ($data['dias_vigencia'] ?? 7));
        Audit::log($request, 'invitacion_cliente_creada', $solicitud, 'Se generó una invitación de acceso vinculada a la solicitud.');

        return response()->json([
            'data' => $snapshot,
            'message' => $snapshot['email_enviado']
                ? 'Invitación creada y enviada por correo.'
                : 'Invitación creada. El correo no pudo entregarse automáticamente; puedes copiar el enlace mientras revisas la configuración del servicio de correo.',
        ], 201);
    }

    public function resendSolicitudInvitation(Request $request, SolicitudSistema $solicitud, AccessInvitationService $service): JsonResponse
    {
        $snapshot = $service->resend($solicitud);
        Audit::log($request, 'invitacion_cliente_reenviada', $solicitud, 'Se intentó reenviar la invitación de acceso del cliente.');

        return response()->json([
            'data' => $snapshot,
            'message' => $snapshot['email_enviado']
                ? 'Invitación reenviada por correo.'
                : 'No se pudo entregar el correo. El enlace sigue disponible para copiarlo manualmente.',
        ]);
    }

    public function revokeSolicitudInvitation(Request $request, SolicitudSistema $solicitud, AccessInvitationService $service): JsonResponse
    {
        $snapshot = $service->revoke($solicitud);
        Audit::log($request, 'invitacion_cliente_revocada', $solicitud, 'Se revocó la invitación de acceso del cliente.');

        return response()->json(['data' => $snapshot, 'message' => 'Invitación revocada.']);
    }

    public function showInvitation(string $token): JsonResponse
    {
        $invitation = $this->resolveInvitation($token)->loadMissing(['cliente','solicitud.empresa']);
        $cliente = $invitation->cliente;
        $solicitud = $invitation->solicitud;
        $empresa = $solicitud?->empresa;

        return response()->json([
            'data' => [
                'estado' => $invitation->estado,
                'expira_at' => $invitation->expira_at,
                'correo' => $invitation->correo_destino ?: $cliente?->correo,
                'vinculada_solicitud' => (bool) ($cliente && $solicitud),
                'prefill' => $cliente && $solicitud ? [
                    'nombre' => $cliente->nombre,
                    'telefono' => $cliente->telefono,
                    'whatsapp' => $cliente->whatsapp,
                    'ciudad' => $cliente->ciudad,
                    'direccion' => $cliente->direccion,
                    'empresa_nombre' => $empresa?->nombre_comercial,
                    'empresa_actividad' => $empresa?->actividad,
                    'empresa_telefono' => $empresa?->telefono,
                    'empresa_whatsapp' => $empresa?->whatsapp,
                    'empresa_ciudad' => $empresa?->ciudad,
                    'empresa_direccion' => $empresa?->direccion,
                    'titulo_sistema' => $solicitud->titulo,
                    'resumen' => $solicitud->resumen,
                ] : null,
            ],
        ]);
    }

    public function register(Request $request, string $token): JsonResponse
    {
        $invitation = $this->resolveInvitation($token);
        $request->merge([
            'usuario' => Str::lower(trim((string) $request->input('usuario'))),
        ]);

        // El cliente ya entregó sus datos en la solicitud. En la invitación solo crea
        // sus credenciales; no se le vuelve a pedir negocio, CI, dirección o proyecto.
        $data = $request->validate([
            'usuario' => ['required','string','alpha_dash','min:4','max:40','not_regex:/^\d+$/','unique:usuarios,usuario'],
            'password' => ['required','confirmed', Password::min(10)->letters()->numbers()],
        ], [
            'usuario.required' => 'Crea un nombre de usuario para ingresar a VITI.',
            'usuario.alpha_dash' => 'El usuario solo puede contener letras, números, guiones y guiones bajos.',
            'usuario.min' => 'El usuario debe tener al menos 4 caracteres.',
            'usuario.not_regex' => 'El usuario debe incluir al menos una letra.',
            'usuario.unique' => 'Ese nombre de usuario ya está registrado.',
            'password.required' => 'Crea una contraseña para tu cuenta VITI.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);

        $result = DB::transaction(function () use ($data, $invitation): array {
            $locked = InvitacionCliente::query()->lockForUpdate()->findOrFail($invitation->id);
            abort_unless(in_array($locked->estado, ['pendiente','enviada'], true), 410, 'Este enlace ya fue utilizado o deshabilitado.');
            abort_if($locked->expira_at && $locked->expira_at->isPast(), 410, 'Este enlace de registro ya venció.');
            abort_unless(filled($locked->cliente_id) && filled($locked->solicitud_id), 422, 'Esta invitación no está vinculada a una solicitud aprobada.');

            $cliente = Cliente::query()->lockForUpdate()->findOrFail($locked->cliente_id);
            $solicitud = SolicitudSistema::query()->lockForUpdate()->findOrFail($locked->solicitud_id);
            abort_unless((int) $solicitud->cliente_id === (int) $cliente->id, 422, 'La invitación no coincide con el responsable de la solicitud.');
            abort_unless($solicitud->estado === 'aprobada', 422, 'La solicitud todavía no está aprobada para crear el acceso.');
            abort_if(Usuario::query()->where('cliente_id', $cliente->id)->exists(), 422, 'Este cliente ya tiene una cuenta. Ingresa a VITI con su usuario y contraseña.');

            $empresa = $solicitud->empresa_id
                ? Empresa::query()->lockForUpdate()->findOrFail($solicitud->empresa_id)
                : null;
            abort_unless($empresa, 422, 'La solicitud todavía no tiene un negocio asociado.');

            $email = Str::lower(trim((string) ($locked->correo_destino ?: $cliente->correo)));
            abort_if($email === '', 422, 'La invitación no tiene un correo de destino.');
            abort_if(Usuario::query()->where('correo', $email)->exists(), 422, 'Ese correo ya pertenece a otra cuenta VITI.');

            $telefono = filled($cliente->telefono) ? (string) $cliente->telefono : null;
            abort_if($telefono && Usuario::query()->where('telefono', $telefono)->exists(), 422, 'El teléfono de esta solicitud ya está asociado a otra cuenta. Contacta al soporte de VITI.');

            $documento = filled($cliente->documento)
                ? preg_replace('/\D+/', '', (string) $cliente->documento)
                : null;
            if ($documento === '') $documento = null;
            abort_if($documento && Usuario::query()->where('documento', $documento)->exists(), 422, 'El documento de esta solicitud ya está asociado a otra cuenta. Contacta al soporte de VITI.');

            if (!$cliente->correo) $cliente->update(['correo' => $email]);

            $usuario = Usuario::create([
                'cliente_id' => $cliente->id,
                'nombre' => $cliente->nombre,
                'usuario' => $data['usuario'],
                'documento' => $documento,
                'telefono' => $telefono,
                'correo' => $email,
                'password' => $data['password'],
                'rol' => 'cliente',
                'estado' => 'activo',
            ]);

            $empresa->usuarios()->syncWithoutDetaching([$usuario->id => [
                'rol_negocio' => 'propietario',
                'activo' => true,
            ]]);

            $cliente->update(['estado' => 'activo']);
            $locked->update([
                'estado' => 'usada',
                'cliente_id' => $cliente->id,
                'solicitud_id' => $solicitud->id,
                'correo_destino' => $email,
                'usada_at' => now(),
            ]);

            return [$cliente, $empresa, $solicitud, $usuario];
        });

        [$cliente, $empresa, $solicitud, $usuario] = $result;

        Audit::log(
            $request,
            'cuenta_cliente_creada',
            $solicitud,
            'El responsable creó sus credenciales mediante la invitación de la solicitud aprobada.',
            ['cliente_id' => $cliente->id, 'empresa_id' => $empresa->id, 'usuario_id' => $usuario->id]
        );

        $admins = Usuario::query()->where('estado','activo')->whereIn('rol',['superadmin','administrador'])->get(['id']);
        $adminMessage = $cliente->nombre.' creó su acceso para '.$solicitud->codigo.'. Ya puedes iniciar el proyecto.';
        foreach ($admins as $admin) {
            AlertaSaas::firstOrCreate(
                ['usuario_id'=>$admin->id,'clave'=>'cuenta_lista_solicitud_'.$solicitud->id],
                [
                    'empresa_id'=>$empresa->id,
                    'tipo'=>'solicitud',
                    'titulo'=>'Cliente listo para iniciar proyecto',
                    'mensaje'=>$adminMessage,
                    'ruta'=>'/solicitudes/'.$solicitud->id,
                ]
            );
        }
        FirebasePush::sendToUsers($admins->pluck('id')->all(), 'Cliente listo para iniciar proyecto', $adminMessage, [
            'type'=>'solicitud',
            'solicitud_id'=>$solicitud->id,
            'path'=>'/solicitudes/'.$solicitud->id,
        ]);

        return response()->json([
            'message' => 'Tu cuenta VITI fue creada. Ya puedes iniciar sesión; el seguimiento continuará desde tu solicitud aprobada.',
            'data' => [
                'cliente' => $cliente,
                'empresa' => $empresa,
                'solicitud_codigo' => $solicitud->codigo,
                'solicitud_estado' => $solicitud->estado,
                'ruta_siguiente' => '/login?registro=ok',
            ],
        ], 201);
    }

    private function resolveInvitation(string $token): InvitacionCliente
    {
        $invitation = InvitacionCliente::query()->where('token', $token)->firstOrFail();
        abort_unless(in_array($invitation->estado, ['pendiente','enviada'], true), 410, 'Este enlace ya fue utilizado o deshabilitado.');
        abort_if($invitation->expira_at && $invitation->expira_at->isPast(), 410, 'Este enlace de registro ya venció.');
        return $invitation;
    }
}
