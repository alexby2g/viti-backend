<?php

namespace App\Services;

use App\Models\AuthSession;
use App\Models\Usuario;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthSessionService
{
    public function registerWeb(Request $request, Usuario $usuario): ?AuthSession
    {
        if (!$request->hasSession() || !$request->session()->getId()) return null;

        $meta = $this->clientMeta($request);
        $hash = hash('sha256', $request->session()->getId());

        $session = AuthSession::query()->firstOrNew(['session_key_hash'=>$hash]);
        if (!$session->exists) {
            $session->usuario_id = $usuario->id;
            $session->tipo = 'web';
            $session->created_at = now();
        }

        if ($session->revoked_at) return $session;

        $session->fill([
            'usuario_id'=>$usuario->id,
            'tipo'=>'web',
            'device_name'=>$meta['device_name'],
            'platform'=>$meta['platform'],
            'browser'=>$meta['browser'],
            'ip_address'=>$request->ip(),
            'fingerprint_hash'=>$meta['fingerprint_hash'],
            'last_seen_at'=>now(),
            'expires_at'=>now()->addMinutes((int) config('session.lifetime',120)),
        ])->save();

        return $session;
    }

    public function registerNative(Request $request, Usuario $usuario, PersonalAccessToken $token, string $deviceName, string $platform): AuthSession
    {
        $meta = $this->clientMeta($request, $platform, $deviceName);

        return AuthSession::query()->updateOrCreate(
            ['personal_access_token_id'=>$token->id],
            [
                'usuario_id'=>$usuario->id,
                'tipo'=>'native',
                'device_name'=>Str::limit(trim($deviceName),120,''),
                'platform'=>$platform,
                'browser'=>null,
                'ip_address'=>$request->ip(),
                'fingerprint_hash'=>$meta['fingerprint_hash'],
                'last_seen_at'=>now(),
                'expires_at'=>$token->expires_at,
                'revoked_at'=>null,
                'revoked_reason'=>null,
            ]
        );
    }

    public function enforce(Request $request, Usuario $usuario): ?AuthSession
    {
        $current = $this->resolveCurrent($request, $usuario, true);
        if (!$current) return null;

        if ($current->revoked_at) {
            abort(401, 'Esta sesión fue cerrada desde Seguridad. Inicia sesión nuevamente.');
        }

        if ($current->expires_at && $current->expires_at->isPast()) {
            $current->forceFill(['revoked_at'=>now(),'revoked_reason'=>'expirada'])->save();
            abort(401, 'Esta sesión venció. Inicia sesión nuevamente.');
        }

        if ($current->tipo === 'web') {
            $meta = $this->clientMeta($request);
            if ($current->fingerprint_hash && !hash_equals($current->fingerprint_hash, $meta['fingerprint_hash'])) {
                $current->forceFill(['revoked_at'=>now(),'revoked_reason'=>'identidad_dispositivo_cambio'])->save();
                Audit::log($request,'sesion_revocada_automaticamente',$usuario,'VITI cerró una sesión porque cambió la identidad del navegador.',[
                    'auth_session_id'=>$current->id,
                ]);
                abort(401, 'VITI cerró esta sesión porque cambió la identidad del dispositivo. Inicia sesión nuevamente.');
            }
        }

        $needsTouch = !$current->last_seen_at
            || $current->last_seen_at->lt(now()->subMinutes(5))
            || (string)$current->ip_address !== (string)$request->ip();

        if ($needsTouch) {
            $current->forceFill([
                'ip_address'=>$request->ip(),
                'last_seen_at'=>now(),
                'expires_at'=>$current->tipo === 'web'
                    ? now()->addMinutes((int) config('session.lifetime',120))
                    : $current->expires_at,
            ])->save();
        }

        return $current;
    }

    public function resolveCurrent(Request $request, Usuario $usuario, bool $createLegacy = false): ?AuthSession
    {
        $token = $usuario->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $existing = AuthSession::query()->where('personal_access_token_id',$token->id)->first();
            if ($existing || !$createLegacy) return $existing;

            $platform = collect($token->abilities ?? [])->first(fn($ability)=>str_starts_with((string)$ability,'platform:'));
            $platform = $platform ? Str::after($platform,'platform:') : 'native';
            return $this->registerNative($request,$usuario,$token,'VITI nativo',$platform);
        }

        if (!$request->hasSession() || !$request->session()->getId()) return null;
        $hash = hash('sha256',$request->session()->getId());
        $existing = AuthSession::query()->where('session_key_hash',$hash)->first();
        if ($existing || !$createLegacy) return $existing;
        return $this->registerWeb($request,$usuario);
    }

    public function revoke(Request $request, Usuario $usuario, AuthSession $session, string $reason = 'revocada_usuario'): void
    {
        abort_unless((int)$session->usuario_id === (int)$usuario->id,403,'No tienes permiso para cerrar esa sesión.');
        if ($session->revoked_at) return;

        DB::transaction(function () use ($session,$reason): void {
            $session->forceFill(['revoked_at'=>now(),'revoked_reason'=>$reason])->save();
            if ($session->personal_access_token_id) {
                PersonalAccessToken::query()->whereKey($session->personal_access_token_id)->delete();
            }
            $this->deleteDatabaseWebSession($session);
        });

        Audit::log($request,'sesion_revocada',$session,'El usuario cerró una sesión desde Seguridad.',[
            'auth_session_id'=>$session->id,
            'tipo'=>$session->tipo,
            'device_name'=>$session->device_name,
        ]);
    }

    public function revokeOthers(Request $request, Usuario $usuario): int
    {
        $current = $this->resolveCurrent($request,$usuario,true);
        $sessions = AuthSession::query()
            ->where('usuario_id',$usuario->id)
            ->whereNull('revoked_at')
            ->when($current,fn($q)=>$q->whereKeyNot($current->id))
            ->get();

        foreach ($sessions as $session) {
            $this->revoke($request,$usuario,$session,'revocada_otras_sesiones');
        }

        return $sessions->count();
    }

    public function listFor(Request $request, Usuario $usuario): array
    {
        $current = $this->resolveCurrent($request,$usuario,true);
        $rows = AuthSession::query()
            ->where('usuario_id',$usuario->id)
            ->where('created_at','>=',now()->subDays(90))
            ->latest('last_seen_at')
            ->latest('id')
            ->limit(50)
            ->get();

        return $rows->map(fn(AuthSession $session)=>[
            'id'=>$session->id,
            'tipo'=>$session->tipo,
            'device_name'=>$session->device_name ?: ($session->tipo === 'web' ? 'Navegador web' : 'VITI nativo'),
            'platform'=>$session->platform,
            'browser'=>$session->browser,
            'ip'=>$this->maskIp($session->ip_address),
            'created_at'=>$session->created_at?->toIso8601String(),
            'last_seen_at'=>$session->last_seen_at?->toIso8601String(),
            'expires_at'=>$session->expires_at?->toIso8601String(),
            'revoked_at'=>$session->revoked_at?->toIso8601String(),
            'revoked_reason'=>$session->revoked_reason,
            'current'=>$current && (int)$current->id === (int)$session->id,
            'active'=>!$session->revoked_at && (!$session->expires_at || $session->expires_at->isFuture()),
        ])->all();
    }

    private function deleteDatabaseWebSession(AuthSession $session): void
    {
        if ($session->tipo !== 'web' || !$session->session_key_hash) return;
        if ((string)config('session.driver') !== 'database') return;

        DB::table((string)config('session.table','sessions'))
            ->where('user_id',$session->usuario_id)
            ->select('id')
            ->get()
            ->each(function ($row) use ($session): void {
                if (hash_equals($session->session_key_hash,hash('sha256',(string)$row->id))) {
                    DB::table((string)config('session.table','sessions'))->where('id',$row->id)->delete();
                }
            });
    }

    private function clientMeta(Request $request, ?string $forcedPlatform = null, ?string $forcedDevice = null): array
    {
        $ua = (string)$request->userAgent();
        $platform = $forcedPlatform ?: match(true) {
            str_contains($ua,'Android')=>'android',
            str_contains($ua,'iPhone'),str_contains($ua,'iPad')=>'ios',
            str_contains($ua,'Windows')=>'windows',
            str_contains($ua,'Macintosh')=>'macos',
            str_contains($ua,'Linux')=>'linux',
            default=>'web',
        };
        $browser = match(true) {
            str_contains($ua,'Edg/')=>'Edge',
            str_contains($ua,'Firefox/')=>'Firefox',
            str_contains($ua,'Chrome/')=>'Chrome',
            str_contains($ua,'Safari/')=>'Safari',
            default=>'Navegador',
        };
        $device = $forcedDevice ?: $browser.' en '.ucfirst($platform);
        return [
            'platform'=>$platform,
            'browser'=>$forcedPlatform ? null : $browser,
            'device_name'=>$device,
            'fingerprint_hash'=>hash('sha256',Str::lower($platform.'|'.($forcedPlatform ? 'native' : $browser))),
        ];
    }

    private function maskIp(?string $ip): ?string
    {
        if (!$ip) return null;
        if (filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) {
            $parts = explode('.',$ip);
            return implode('.',array_slice($parts,0,3)).'.*';
        }
        if (filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)) {
            $parts = array_values(array_filter(explode(':',$ip),fn($part)=>$part !== ''));
            return implode(':',array_slice($parts,0,3)).':*';
        }
        return null;
    }
}
