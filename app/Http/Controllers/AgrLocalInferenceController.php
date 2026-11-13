<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgrLocalInferenceController extends Controller
{
    /**
     * Only an authenticated VITI platform administrator can enqueue a question.
     * The worker does not have database or general VITI API access.
     */
    public function submit(Request $request): JsonResponse
    {
        if (!config('services.irenet_bridge.enabled', false)) {
            return response()->json([
                'code' => 'irenet_bridge_disabled',
                'message' => 'El conector de IRENET todavía no está habilitado.',
            ], 503)->header('Cache-Control', 'no-store');
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'max:500'],
        ]);
        $message = trim($data['message']);
        if ($message === '') {
            return response()->json(['message' => 'Escribe una pregunta.'], 422);
        }

        // Clean up *only* old IRENET inference entries; never touch other tables.
        DB::table('agr_local_inference_jobs')->where('created_at', '<', now()->subDay())->delete();

        $id = (string) Str::uuid();
        DB::table('agr_local_inference_jobs')->insert([
            'id' => $id,
            'usuario_id' => $request->user()->id,
            'encrypted_prompt' => Crypt::encryptString($message),
            'status' => 'pending',
            'expires_at' => now()->addMinutes(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'id' => $id,
            'status' => 'pending',
        ], 202)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        if (!Str::isUuid($id)) {
            return response()->json(['message' => 'Solicitud no encontrada.'], 404);
        }

        $job = DB::table('agr_local_inference_jobs')
            ->where('id', $id)
            ->where('usuario_id', $request->user()->id)
            ->first();

        if (!$job) {
            return response()->json(['message' => 'Solicitud no encontrada.'], 404);
        }

        $expired = now()->greaterThan($job->expires_at);
        $status = $expired && $job->status !== 'completed' ? 'expired' : $job->status;

        return response()->json([
            'id' => $job->id,
            'status' => $status,
            'message' => $status === 'completed' && !$expired
                ? Crypt::decryptString((string) $job->encrypted_answer)
                : null,
        ])->header('Cache-Control', 'no-store');
    }
}
