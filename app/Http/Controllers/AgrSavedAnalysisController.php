<?php

namespace App\Http\Controllers;

use App\Models\SolicitudSistema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgrSavedAnalysisController extends Controller
{
    /**
     * Explicit save only. An arbitrary text answer cannot be archived as a
     * request analysis: the worker job must have been created via
     * /assistant/local/analyze-request and belong to this administrator.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'job_id' => ['required', 'uuid'],
        ]);

        $saved = DB::transaction(function () use ($request, $data): array {
            $job = DB::table('agr_local_inference_jobs')
                ->where('id', $data['job_id'])
                ->where('usuario_id', $request->user()->id)
                ->lockForUpdate()
                ->first();

            if (!$job || !$job->analysis_solicitud_id) {
                abort(404, 'Análisis no encontrado o no autorizado.');
            }

            if ($job->status !== 'completed' || !$job->encrypted_answer) {
                abort(409, 'El análisis todavía no está completo.');
            }

            // Lock the parent to serialize version allocation for this request.
            $solicitud = SolicitudSistema::query()
                ->whereKey($job->analysis_solicitud_id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = DB::table('agr_saved_analyses')
                ->where('source_job_id', $job->id)
                ->first();

            if ($existing) {
                return [
                    'id' => $existing->id,
                    'codigo' => $solicitud->codigo,
                    'version' => $existing->version,
                    'estado' => $existing->estado,
                    'already_saved' => true,
                ];
            }

            $content = trim(Crypt::decryptString((string) $job->encrypted_answer));
            if ($content === '') {
                abort(409, 'No se puede guardar un análisis vacío.');
            }

            $version = (int) DB::table('agr_saved_analyses')
                ->where('solicitud_id', $solicitud->id)
                ->max('version') + 1;

            $id = DB::table('agr_saved_analyses')->insertGetId([
                'solicitud_id' => $solicitud->id,
                'usuario_id' => $request->user()->id,
                'source_job_id' => $job->id,
                'version' => $version,
                'estado' => 'borrador',
                'encrypted_content' => Crypt::encryptString($content),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'id' => $id,
                'codigo' => $solicitud->codigo,
                'version' => $version,
                'estado' => 'borrador',
                'already_saved' => false,
            ];
        });

        return response()->json($saved, $saved['already_saved'] ? 200 : 201)
            ->header('Cache-Control', 'no-store');
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo' => ['required', 'string', 'regex:/^SOL-[0-9]{3,10}$/i'],
        ]);

        $solicitud = SolicitudSistema::query()
            ->where('codigo', Str::upper($data['codigo']))
            ->firstOrFail();

        $records = DB::table('agr_saved_analyses')
            ->where('solicitud_id', $solicitud->id)
            ->orderByDesc('version')
            ->limit(30)
            ->get(['id', 'version', 'estado', 'created_at']);

        return response()->json([
            'codigo' => $solicitud->codigo,
            'items' => $records,
        ])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $record = DB::table('agr_saved_analyses')
            ->join('solicitudes_sistema', 'solicitudes_sistema.id', '=', 'agr_saved_analyses.solicitud_id')
            ->whereNull('solicitudes_sistema.deleted_at')
            ->where('agr_saved_analyses.id', $id)
            ->first([
                'agr_saved_analyses.id',
                'agr_saved_analyses.version',
                'agr_saved_analyses.estado',
                'agr_saved_analyses.encrypted_content',
                'agr_saved_analyses.created_at',
                'solicitudes_sistema.codigo',
            ]);

        if (!$record) {
            return response()->json(['message' => 'Análisis no encontrado.'], 404)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'id' => $record->id,
            'codigo' => $record->codigo,
            'version' => $record->version,
            'estado' => $record->estado,
            'created_at' => $record->created_at,
            'content' => Crypt::decryptString($record->encrypted_content),
        ])->header('Cache-Control', 'no-store');
    }
}
