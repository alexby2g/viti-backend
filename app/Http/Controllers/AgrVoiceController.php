<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AgrVoiceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:5000'],
        ]);

        $apiKey = (string) config('services.elevenlabs.api_key');
        $voiceId = (string) config('services.elevenlabs.voice_id');
        $modelId = (string) config('services.elevenlabs.model_id', 'eleven_multilingual_v2');
        $outputFormat = (string) config('services.elevenlabs.output_format', 'mp3_44100_128');

        if ($apiKey === '' || $voiceId === '') {
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
            report(new \RuntimeException('ElevenLabs TTS failed: '.$response->status()));

            return response()->json([
                'message' => '006 no pudo generar audio en este momento.',
                'code' => 'AGR_VOICE_PROVIDER_ERROR',
            ], 502);
        }

        return response()->json([
            'audio' => base64_encode($response->body()),
            'mime' => $response->header('Content-Type') ?: 'audio/mpeg',
            'voice' => $voiceId,
        ]);
    }
}
