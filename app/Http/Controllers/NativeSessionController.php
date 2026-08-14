<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class NativeSessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $currentId = $request->user()?->currentAccessToken()?->getKey();
        $sessions = $request->user()
            ->tokens()
            ->where('name', 'like', 'viti-native:%')
            ->latest('id')
            ->get()
            ->map(fn (PersonalAccessToken $token): array => $this->serialize($token, $currentId));

        return response()->json(['data' => $sessions]);
    }

    public function revoke(Request $request, int $tokenId): JsonResponse
    {
        $token = $request->user()
            ->tokens()
            ->whereKey($tokenId)
            ->where('name', 'like', 'viti-native:%')
            ->first();

        abort_unless($token, 404, 'La sesión indicada no existe o no pertenece a tu cuenta.');

        $isCurrent = (int) $request->user()->currentAccessToken()?->getKey() === (int) $token->id;
        Audit::log($request, 'sesion_nativa_revocada', $request->user(), 'Se revocó una sesión nativa de VITI.', [
            'sesion_id' => (int) $token->id,
            'sesion_actual' => $isCurrent,
            'dispositivo_hash' => hash('sha256', (string) $token->name),
        ]);
        $token->delete();

        return response()->json([
            'message' => $isCurrent
                ? 'La sesión actual fue cerrada correctamente.'
                : 'La sesión del dispositivo fue revocada correctamente.',
            'sesion_actual_revocada' => $isCurrent,
        ]);
    }

    public function revokeOthers(Request $request): JsonResponse
    {
        $currentId = $request->user()?->currentAccessToken()?->getKey();
        abort_unless($currentId, 401, 'La sesión actual no se pudo identificar.');

        $query = $request->user()
            ->tokens()
            ->where('name', 'like', 'viti-native:%')
            ->where('id', '!=', $currentId);
        $count = (clone $query)->count();

        Audit::log($request, 'sesiones_nativas_otras_revocadas', $request->user(), 'Se cerraron las demás sesiones nativas de la cuenta.', [
            'cantidad' => $count,
        ]);
        $query->delete();

        return response()->json([
            'message' => $count === 1 ? 'Se cerró 1 sesión adicional.' : "Se cerraron {$count} sesiones adicionales.",
            'revocadas' => $count,
        ]);
    }

    public function revokeAll(Request $request): JsonResponse
    {
        $query = $request->user()->tokens()->where('name', 'like', 'viti-native:%');
        $count = (clone $query)->count();

        Audit::log($request, 'sesiones_nativas_todas_revocadas', $request->user(), 'Se cerraron todas las sesiones nativas de la cuenta.', [
            'cantidad' => $count,
        ]);
        $query->delete();

        return response()->json([
            'message' => 'Todas las sesiones nativas fueron cerradas.',
            'revocadas' => $count,
        ]);
    }

    private function serialize(PersonalAccessToken $token, mixed $currentId): array
    {
        $abilities = is_array($token->abilities) ? $token->abilities : [];
        $platform = null;
        foreach ($abilities as $ability) {
            if (str_starts_with((string) $ability, 'platform:')) {
                $platform = substr((string) $ability, strlen('platform:'));
                break;
            }
        }

        return [
            'id' => (int) $token->id,
            'dispositivo_id' => str_starts_with((string) $token->name, 'viti-native:')
                ? substr((string) $token->name, strlen('viti-native:'))
                : null,
            'plataforma' => $platform,
            'actual' => (int) $currentId === (int) $token->id,
            'ultimo_uso' => $token->last_used_at?->toIso8601String(),
            'creada' => $token->created_at?->toIso8601String(),
            'expira' => $token->expires_at?->toIso8601String(),
        ];
    }
}
