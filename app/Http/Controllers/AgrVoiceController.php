<?php

namespace App\Http\Controllers;

use App\Services\{AgrAssistantService, AgrLocalLanguageService, AgrMemoryService, AgrProjectConversionService};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AgrVoiceController extends Controller
{
    public function __invoke(
        Request $request,
        AgrLocalLanguageService $language,
        AgrMemoryService $memory,
        AgrAssistantService $assistant,
        AgrProjectConversionService $projectConversion
    ): Response|JsonResponse {
        // 006 command channel: local VITI language engine first, then the existing AGR stack.
        if ($request->filled('command')) {
            $input = trim((string) $request->input('command'));
            if ($input === '') return response()->json(['message' => '006 recibió un comando vacío.'], 422);

            $local = $language->interpret($input);
            if ($local !== null) return response()->json($local);

            $conversion = $projectConversion->handle($input);
            if ($conversion !== null) return response()->json($conversion);

            return response()->json($memory->handle($input, $assistant));
        }

        $data = $request->validate([
            'text' => ['required', 'string', 'max:5000'],
        ]);

        $apiKey = (string) config('services.elevenlabs.api_key');
        $voiceId = (string) config('services.elevenlabs.voice_id');
        $modelId = (string) config('services.elevenlabs.model_id', 'eleven_multilingual_v2');
        $outputFormat = (string) config('services.elevenlabs.output_format', 'mp3_44100_128');

        if ($apiKey === '' || $voiceId === '') {
            Log::warning('AGR 006 premium voice is not configured', [
                'has_api_key' => $apiKey !== '',
                'has_voice_id' => $voiceId !== '',
                'voice_id' => $voiceId,
                'model_id' => $modelId,
                'output_format' => $outputFormat,
            ]);

            return response()->json([
                'message' => 'La voz premium de 006 no está configurada todavía.',
                'code' => 'AGR_VOICE_NOT_CONFIGURED',
            ], 503);
        }

        $response = Http::timeout(25)
            ->withHeaders([
                'xi-api-key' => $apiKey,
                'Accept' => 'audio/mpeg',
                'Content-Type' => 'application/json',
            ])
            ->post("https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}?output_format={$outputFormat}", [
                'text' => $data['text'],
                'model_id' => $modelId,
                'voice_settings' => [
                    'stability' => 0.62,
                    'similarity_boost' => 0.82,
                    'style' => 0.18,
                    'use_speaker_boost' => true,
                ],
            ]);

        if (!$response->successful()) {
            $providerBody = $response->body();
            Log::error('AGR 006 ElevenLabs TTS failed', [
                'status' => $response->status(),
                'voice_id' => $voiceId,
                'model_id' => $modelId,
                'output_format' => $outputFormat,
                'provider_body' => mb_substr($providerBody, 0, 2000),
            ]);

            return response()->json([
                'message' => '006 no pudo generar audio en este momento.',
                'code' => 'AGR_VOICE_PROVIDER_ERROR',
            ], 502);
        }

        return response($response->body(), 200, [
            'Content-Type' => $response->header('Content-Type') ?: 'audio/mpeg',
            'Content-Disposition' => 'inline; filename="006.mp3"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
