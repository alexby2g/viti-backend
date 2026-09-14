<?php

namespace App\Services;

use App\Mail\VitiAccessInvitation;
use App\Models\{InvitacionCliente,SolicitudSistema,Usuario};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AccessInvitationService
{
    public function createAndSend(SolicitudSistema $solicitud, ?Usuario $actor, int $days = 7): array
    {
        $invitation = DB::transaction(function () use ($solicitud, $actor, $days): InvitacionCliente {
            $solicitud = SolicitudSistema::query()
                ->with(['cliente.usuario','empresa'])
                ->lockForUpdate()
                ->findOrFail($solicitud->id);

            abort_unless($solicitud->estado === 'aprobada', 422, 'La solicitud debe estar aprobada antes de habilitar el acceso del cliente.');

            $cliente = $solicitud->cliente;
            abort_unless($cliente, 422, 'La solicitud no tiene un responsable asociado.');
            abort_if($cliente->usuario, 422, 'Este responsable ya tiene una cuenta VITI activa.');

            $email = Str::lower(trim((string) $cliente->correo));
            if ($email === '') {
                throw ValidationException::withMessages([
                    'correo' => 'Registra el correo del responsable antes de enviar una invitación.',
                ]);
            }

            if (Usuario::query()->where('correo', $email)->where(function ($query) use ($cliente): void {
                $query->whereNull('cliente_id')->orWhere('cliente_id', '!=', $cliente->id);
            })->exists()) {
                throw ValidationException::withMessages([
                    'correo' => 'Ese correo ya pertenece a otra cuenta VITI. Corrige el responsable antes de aprobar.',
                ]);
            }

            $active = InvitacionCliente::query()
                ->where('solicitud_id', $solicitud->id)
                ->whereIn('estado', ['pendiente','enviada'])
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($active && $active->expira_at?->isPast()) {
                $active->update(['estado' => 'vencida']);
                $active = null;
            }

            if (!$active) {
                $active = InvitacionCliente::create([
                    'token' => Str::random(64),
                    'creada_por' => $actor?->id,
                    'cliente_id' => $cliente->id,
                    'solicitud_id' => $solicitud->id,
                    'correo_destino' => $email,
                    'estado' => 'pendiente',
                    'expira_at' => now()->addDays($days),
                ]);
            } else {
                $active->update([
                    'cliente_id' => $cliente->id,
                    'correo_destino' => $email,
                    'creada_por' => $active->creada_por ?: $actor?->id,
                ]);
            }

            return $active->fresh(['cliente','solicitud.empresa']);
        });

        $sent = $this->send($invitation);
        return $this->snapshot($invitation->fresh(['cliente.usuario','solicitud.empresa']), $sent);
    }

    public function resend(SolicitudSistema $solicitud): array
    {
        abort_unless($solicitud->estado === 'aprobada', 422, 'Solo se puede reenviar una invitación cuando la solicitud está aprobada.');
        $invitation = InvitacionCliente::query()
            ->where('solicitud_id', $solicitud->id)
            ->whereIn('estado', ['pendiente','enviada'])
            ->latest('id')
            ->first();

        abort_unless($invitation, 422, 'No existe una invitación activa para reenviar.');
        $invitation->loadMissing('cliente');
        $currentEmail = Str::lower(trim((string) ($invitation->cliente?->correo ?: $invitation->correo_destino)));
        abort_if($currentEmail === '', 422, 'El responsable no tiene un correo válido para reenviar la invitación.');
        if ($currentEmail !== $invitation->correo_destino) {
            $invitation->update(['correo_destino' => $currentEmail]);
        }
        if ($invitation->expira_at?->isPast()) {
            $invitation->update(['estado' => 'vencida']);
            abort(410, 'La invitación venció. Genera una nueva invitación para continuar.');
        }

        $sent = $this->send($invitation);
        return $this->snapshot($invitation->fresh(['cliente.usuario','solicitud.empresa']), $sent);
    }

    public function revoke(SolicitudSistema $solicitud): array
    {
        $invitation = InvitacionCliente::query()
            ->where('solicitud_id', $solicitud->id)
            ->whereIn('estado', ['pendiente','enviada'])
            ->latest('id')
            ->first();

        abort_unless($invitation, 422, 'No existe una invitación activa para revocar.');
        $invitation->update(['estado' => 'revocada', 'revocada_at' => now()]);

        return $this->snapshot($invitation->fresh(['cliente.usuario','solicitud.empresa']));
    }

    public function snapshotForSolicitud(SolicitudSistema $solicitud): array
    {
        $solicitud->loadMissing(['cliente.usuario','empresa']);
        $cliente = $solicitud->cliente;

        if (!$cliente) {
            return [
                'estado' => 'sin_responsable',
                'correo' => null,
                'tiene_cuenta' => false,
                'puede_enviar' => false,
            ];
        }

        if ($cliente->usuario) {
            return [
                'estado' => 'cuenta_creada',
                'correo' => $cliente->correo ?: $cliente->usuario->correo,
                'tiene_cuenta' => true,
                'usuario' => $cliente->usuario->usuario,
                'puede_enviar' => false,
            ];
        }

        $invitation = InvitacionCliente::query()
            ->where('solicitud_id', $solicitud->id)
            ->latest('id')
            ->first();

        if (!$invitation) {
            return [
                'estado' => filled($cliente->correo) ? 'pendiente_aprobacion' : 'sin_correo',
                'correo' => $cliente->correo,
                'tiene_cuenta' => false,
                'puede_enviar' => filled($cliente->correo) && $solicitud->estado === 'aprobada',
            ];
        }

        return $this->snapshot($invitation->loadMissing(['cliente.usuario','solicitud.empresa']));
    }

    public function snapshot(InvitacionCliente $invitation, ?bool $sentNow = null): array
    {
        $invitation->loadMissing(['cliente.usuario','solicitud.empresa']);
        $state = $this->effectiveState($invitation);
        $active = in_array($state, ['pendiente','enviada'], true);
        $front = rtrim((string) env('FRONTEND_APP_URL', 'http://localhost:9000'), '/');
        $route = '/registro-cliente/'.$invitation->token;

        return [
            'id' => $invitation->id,
            'estado' => $state,
            'correo' => $invitation->correo_destino ?: $invitation->cliente?->correo,
            'tiene_cuenta' => (bool) $invitation->cliente?->usuario,
            'expira_at' => $invitation->expira_at,
            'enviada_at' => $invitation->enviada_at,
            'ultimo_envio_at' => $invitation->ultimo_envio_at,
            'intentos_envio' => (int) $invitation->intentos_envio,
            'usada_at' => $invitation->usada_at,
            'revocada_at' => $invitation->revocada_at,
            'error_envio' => $invitation->error_envio,
            'ruta' => $active ? $route : null,
            'url' => $active ? $front.$route : null,
            'email_enviado' => $sentNow ?? (bool) $invitation->enviada_at,
            'puede_enviar' => $active,
            'puede_reenviar' => $active,
            'puede_revocar' => $active,
        ];
    }

    private function effectiveState(InvitacionCliente $invitation): string
    {
        if ($invitation->estado === 'usada' || $invitation->usada_at) return 'usada';
        if ($invitation->estado === 'revocada' || $invitation->revocada_at) return 'revocada';
        if ($invitation->estado === 'vencida' || ($invitation->expira_at && $invitation->expira_at->isPast())) return 'vencida';
        return $invitation->estado ?: 'pendiente';
    }

    private function send(InvitacionCliente $invitation): bool
    {
        $provider = Str::lower(trim((string) config('services.transactional_mail.provider', 'laravel')));

        try {
            if ($provider === 'brevo') {
                app(BrevoTransactionalEmailService::class)->sendInvitation($invitation);
            } else {
                $mailer = (string) config('mail.default', 'log');
                $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);
                $deliveryEnabled = !in_array($transport, ['log','array'], true);

                if (!$deliveryEnabled) {
                    $invitation->update([
                        'ultimo_envio_at' => now(),
                        'intentos_envio' => ((int) $invitation->intentos_envio) + 1,
                        'error_envio' => 'El servicio de correo todavía no está configurado para entrega real. Puedes copiar el enlace de invitación mientras se completa la configuración.',
                    ]);
                    return false;
                }

                Mail::to($invitation->correo_destino)->send(new VitiAccessInvitation($invitation));
            }

            $invitation->update([
                'estado' => 'enviada',
                'enviada_at' => $invitation->enviada_at ?: now(),
                'ultimo_envio_at' => now(),
                'intentos_envio' => ((int) $invitation->intentos_envio) + 1,
                'error_envio' => null,
            ]);
            return true;
        } catch (Throwable $e) {
            report($e);
            $invitation->update([
                'ultimo_envio_at' => now(),
                'intentos_envio' => ((int) $invitation->intentos_envio) + 1,
                'error_envio' => Str::limit($e->getMessage(), 1000),
            ]);
            return false;
        }
    }
}
