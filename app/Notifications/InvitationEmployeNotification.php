<?php

namespace App\Notifications;

use App\Models\InvitationEmploye;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Contracts\Queue\ShouldQueue;

class InvitationEmployeNotification extends Notification
{
    use Queueable;

    public function __construct(public InvitationEmploye $invitation) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $frontendUrl = rtrim(config('app.frontend_url'), '/');
        $url = "{$frontendUrl}/invitation/accepter?token={$this->invitation->token}";

        return (new MailMessage)
            ->subject("Invitation à rejoindre {$this->invitation->entreprise->nom} sur Relancia")
            ->greeting("Bonjour {$this->invitation->nom},")
            ->line("Vous avez été invité(e) à rejoindre {$this->invitation->entreprise->nom} en tant qu'employé sur Relancia.")
            ->action("Accepter l'invitation", $url)
            ->line("Ce lien expire le {$this->invitation->expire_le->format('d/m/Y à H:i')}.");
    }
}
