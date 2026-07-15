<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $resetUrl)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Réinitialisation de votre mot de passe Relancia')
            ->greeting("Bonjour {$notifiable->name},")
            ->line('Vous recevez cet email car nous avons reçu une demande de réinitialisation de mot de passe pour votre compte.')
            ->action('Réinitialiser mon mot de passe', $this->resetUrl)
            ->line('Ce lien expirera dans 60 minutes.')
            ->line("Si vous n'êtes pas à l'origine de cette demande, aucune action n'est requise.");
    }
}
