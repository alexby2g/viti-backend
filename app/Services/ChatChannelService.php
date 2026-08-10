<?php

namespace App\Services;

use App\Models\{Aplicacion,Cliente,Conversacion,Usuario};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ChatChannelService
{
    public const VITI = 'viti';
    public const ELECTROFRIO = 'electrofrio';

    public function context(Request $request): string
    {
        $context = (string) ($request->route('chat_context') ?: self::VITI);
        abort_unless(in_array($context, [self::VITI, self::ELECTROFRIO], true), 404, 'El buzón solicitado no existe.');
        return $context;
    }

    public function clientChannel(Request $request, ?string $context = null): Conversacion
    {
        $context ??= $this->context($request);
        $clientId = (int) $request->user()->cliente_id;

        if ($context === self::VITI) return $this->ensure($clientId, self::VITI);

        $empresa = app(TenantContext::class)->resolve($request);
        $app = Aplicacion::query()
            ->where('empresa_id', $empresa->id)
            ->where('estado', 'activo')
            ->where('acceso_cliente', true)
            ->whereHas('catalogo', fn (Builder $query) => $query->where('clave', self::ELECTROFRIO))
            ->latest('id')
            ->first();

        abort_unless($app, 404, 'Este negocio no tiene un buzón de Electrofrío activo.');
        return $this->ensure($clientId, self::ELECTROFRIO, (int) $empresa->id, (int) $app->id);
    }

    public function ensureAdminChannels(string $context): void
    {
        if ($context === self::VITI) {
            Cliente::query()->select('id')->orderBy('id')->chunkById(100, function ($clients): void {
                foreach ($clients as $client) $this->ensure((int) $client->id, self::VITI);
            });
            return;
        }

        Aplicacion::query()
            ->where('estado', 'activo')
            ->where('acceso_cliente', true)
            ->whereHas('catalogo', fn (Builder $query) => $query->where('clave', self::ELECTROFRIO))
            ->with('empresa:id,cliente_id')
            ->orderBy('id')
            ->chunkById(100, function ($apps): void {
                foreach ($apps as $app) {
                    if ($app->empresa?->cliente_id) {
                        $this->ensure((int) $app->empresa->cliente_id, self::ELECTROFRIO, (int) $app->empresa_id, (int) $app->id);
                    }
                }
            });
    }

    public function adminQuery(string $context): Builder
    {
        return Conversacion::query()->where('contexto', $context)->where('canal_principal', true);
    }

    public function assertContext(Conversacion $conversation, string $context): void
    {
        abort_unless($conversation->contexto === $context && $conversation->canal_principal, 404, 'Esta conversación no pertenece a este buzón.');
    }

    public function assertClient(Request $request, Conversacion $conversation, string $context): void
    {
        $this->assertContext($conversation, $context);
        abort_unless((int) $conversation->cliente_id === (int) $request->user()->cliente_id, 403, 'No tienes permiso para acceder a esta conversación.');

        if ($context === self::ELECTROFRIO) {
            $channel = $this->clientChannel($request, $context);
            abort_unless((int) $channel->id === (int) $conversation->id, 403, 'Esta conversación pertenece a otro negocio.');
        }
    }

    public function label(string $context): string
    {
        return $context === self::ELECTROFRIO ? 'Electrofrío' : 'Atención VITI';
    }

    public function adminPath(Conversacion $conversation): string
    {
        return $conversation->contexto === self::ELECTROFRIO ? '/apps/electrofrio/buzon' : '/buzon';
    }

    public function clientPath(Conversacion $conversation): string
    {
        return $conversation->contexto === self::ELECTROFRIO ? '/mi-apps/electrofrio/buzon' : '/mi-buzon';
    }

    private function ensure(int $clientId, string $context, ?int $businessId = null, ?int $appId = null): Conversacion
    {
        $query = Conversacion::query()
            ->where('cliente_id', $clientId)
            ->where('contexto', $context)
            ->where('canal_principal', true);

        if ($businessId) $query->where('empresa_id', $businessId);
        if ($appId) $query->where('aplicacion_id', $appId);
        if ($channel = $query->first()) return $channel;

        $adminId = Usuario::query()
            ->where('estado', 'activo')
            ->where('rol', 'superadmin')
            ->orderBy('id')
            ->value('id');

        Conversacion::query()
            ->where('cliente_id', $clientId)
            ->where('contexto', $context)
            ->when($businessId, fn (Builder $q) => $q->where('empresa_id', $businessId))
            ->update(['canal_principal' => false]);

        return Conversacion::create([
            'cliente_id' => $clientId,
            'empresa_id' => $businessId,
            'aplicacion_id' => $appId,
            'responsable_usuario_id' => $adminId,
            'asunto' => $this->label($context),
            'estado' => 'abierta',
            'contexto' => $context,
            'canal_principal' => true,
        ]);
    }
}
