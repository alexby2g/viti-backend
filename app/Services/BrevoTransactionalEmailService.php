<?php

namespace App\Services;

use App\Models\InvitacionCliente;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BrevoTransactionalEmailService
{
    public function sendInvitation(InvitacionCliente $invitation): string
    {
        $invitation->loadMissing(['cliente', 'solicitud.empresa']);

        $apiKey = trim((string) config('services.brevo.api_key'));
        $fromEmail = trim((string) config('services.brevo.from_email'));
        $fromName = trim((string) config('services.brevo.from_name', 'VITI'));
        $apiUrl = rtrim((string) config('services.brevo.api_url', 'https://api.brevo.com/v3'), '/');

        if ($apiKey === '') {
            throw new RuntimeException('BREVO_API_KEY no está configurada en el servidor.');
        }
        if ($fromEmail === '') {
            throw new RuntimeException('BREVO_FROM_EMAIL no está configurado en el servidor.');
        }
        if (blank($invitation->correo_destino)) {
            throw new RuntimeException('La invitación no tiene un correo de destino.');
        }

        $url = rtrim((string) env('FRONTEND_APP_URL', 'http://localhost:9000'), '/')
            .'/registro-cliente/'.$invitation->token;

        $payload = [
            'sender' => [
                'name' => $fromName,
                'email' => $fromEmail,
            ],
            'to' => [[
                'email' => $invitation->correo_destino,
                'name' => $invitation->cliente?->nombre ?: $invitation->correo_destino,
            ]],
            'subject' => 'Tu acceso a VITI ha sido aprobado',
            'htmlContent' => view('emails.viti-access-invitation', [
                'cliente' => $invitation->cliente,
                'solicitud' => $invitation->solicitud,
                'empresa' => $invitation->solicitud?->empresa,
                'url' => $url,
                'expiraAt' => $invitation->expira_at,
            ])->render(),
        ];

        $replyToEmail = trim((string) config('services.brevo.reply_to_email'));
        if ($replyToEmail !== '') {
            $payload['replyTo'] = [
                'email' => $replyToEmail,
                'name' => trim((string) config('services.brevo.reply_to_name', $fromName)) ?: $fromName,
            ];
        }

        $response = Http::acceptJson()
            ->withHeaders(['api-key' => $apiKey])
            ->connectTimeout(5)
            ->timeout(15)
            ->retry(2, 400, null, false)
            ->post($apiUrl.'/smtp/email', $payload);

        $this->assertSuccessful($response);

        $messageId = trim((string) $response->json('messageId'));
        return $messageId !== '' ? $messageId : 'brevo-sent';
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $message = trim((string) ($response->json('message') ?: $response->json('code')));
        if ($message === '') {
            $message = 'Brevo respondió HTTP '.$response->status().'.';
        }

        throw new RuntimeException('Brevo: '.$message);
    }
}
