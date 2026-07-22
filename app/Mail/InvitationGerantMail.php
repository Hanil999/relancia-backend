<?php

namespace App\Mail;

use App\Models\InvitationGerant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvitationGerantMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public InvitationGerant $invitation)
    {
    }

    public function build()
{
    $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:8081')), '/');
    $lienInvitation = "{$frontendUrl}/invitation-gerant/{$this->invitation->token}"; // <-- corrigé

    return $this
        ->subject("Invitation à gérer {$this->invitation->entreprise_nom} sur Relancia")
        ->view('emails.invitation-gerant')
        ->with([
            'nom' => $this->invitation->nom,
            'entrepriseNom' => $this->invitation->entreprise_nom,
            'lienInvitation' => $lienInvitation,
            'expiresAt' => $this->invitation->expires_at,
        ]);
}
}
