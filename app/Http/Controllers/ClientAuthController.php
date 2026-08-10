<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class ClientAuthController extends Controller
{
    /**
     * El registro directo quedó retirado. Las cuentas nuevas deben crearse
     * exclusivamente mediante una invitación personal de onboarding.
     */
    public function register(): JsonResponse
    {
        return response()->json([
            'message' => 'El registro directo ya no está disponible. Solicita a AGR Studio un enlace personal de registro VITI.',
            'codigo' => 'registro_por_invitacion',
        ], 410);
    }
}
