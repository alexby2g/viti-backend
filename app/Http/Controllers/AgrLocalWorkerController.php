<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgrLocalWorkerController extends Controller
{
    private function authorized(Request $request): bool
    {
        $secret = (string) config('services.irenet_bridge.secret', '');
        $submitted = (string) $request->header('X-Irenet-Bridge-Token', '');

        return config('services.irenet_bridge.enabled', false)
            && strlen($secret) >= 32
            && strlen($submitted) >= 32
            && hash_equals($secret, $submitted);
    }

    public function next(Request $request): JsonResponse
    {
        abort_unless($this->authorized($request), 403, 'Conector IRENET no autorizado.');

        // Lock a single pending job atomically to prevent duplicate processing.
        $result = DB::transaction(function () {
            $job = DB::table('agr_local_inference_jobs')
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            if (!$job) return null;

            DB::table('agr_local_inference_jobs')->where('id', $job->id)->update([
                'status' => 'processing',
                'claimed_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'id' => $job->id,
                'message' => Crypt::decryptString($job->encrypted_prompt),
            ];
        });

        return $result
            ? response()->json($result)->header('Cache-Control', 'no-store')
            : response()->json([], 204)->header('Cache-Control', 'no-store');
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        abort_unless($this->authorized($request), 403, 'Conector IRENET no autorizado.');
        abort_unless(Str::isUuid($id), 404);

        $data = $request->validate([
            'status' => ['required', 'in:completed,failed'],
            'answer' => ['required_if:status,completed', 'nullable', 'string', 'max:6000'],
        ]);

        $answer = trim((string) ($data['answer'] ?? ''));
        if ($data['status'] === 'completed' && $answer === '') {
            return response()->json(['message' => 'La respuesta está vacía.'], 422);
        }

        $affected = DB::table('agr_local_inference_jobs')
            ->where('id', $id)
            ->where('status', 'processing')
            ->where('expires_at', '>', now())
            ->update([
                'status' => $data['status'],
                'encrypted_answer' => $data['status'] === 'completed' ? Crypt::encryptString($answer) : null,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            return response()->json([
                'message' => 'La consulta ya terminó o ha expirado.',
            ], 409);
        }

        return response()->json(['status' => $data['status']])
            ->header('Cache-Control', 'no-store');
    }
}
