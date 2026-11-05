<?php

namespace App\Mail;

use App\Models\InvitacionCliente;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VitiAccessInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public InvitacionCliente $invitation)
    {
        $this->invitation->loadMissing(['cliente','solicitud.empresa']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu acceso a VITI ha sido aprobado');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.viti-access-invitation',
            with: [
                'cliente' => $this->invitation->cliente,
                'solicitud' => $this->invitation->solicitud,
                'empresa' => $this->invitation->solicitud?->empresa,
                'url' => app(\App\Services\AccessInvitationService::class)->invitationUrl($this->invitation),
                'expiraAt' => $this->invitation->expira_at,
            ],
        );
    }
}
