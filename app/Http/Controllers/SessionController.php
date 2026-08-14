<?php

namespace App\Http\Controllers;

use App\Models\AuthSession;
use App\Services\AuthSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function index(Request $request, AuthSessionService $sessions): JsonResponse
    {
        return response()->json(['data'=>$sessions->listFor($request,$request->user())]);
    }

    public function destroy(Request $request, AuthSession $authSession, AuthSessionService $sessions): JsonResponse
    {
        $current = $sessions->resolveCurrent($request,$request->user(),true);
        abort_if($current && (int)$current->id === (int)$authSession->id,422,'Para cerrar esta sesión actual usa el botón Cerrar sesión.');
        $sessions->revoke($request,$request->user(),$authSession);
        return response()->json(['message'=>'Sesión cerrada correctamente.']);
    }

    public function destroyOthers(Request $request, AuthSessionService $sessions): JsonResponse
    {
        $count = $sessions->revokeOthers($request,$request->user());
        return response()->json([
            'message'=>$count ? "Se cerraron {$count} sesiones adicionales." : 'No había otras sesiones activas.',
            'revocadas'=>$count,
        ]);
    }
}
