<?php

namespace App\Services;

use App\Models\CanalEntreprise;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class TelegramService
{
    private const API_BASE = 'https://api.telegram.org/bot';

    /**
     * Vérifie le token en appelant getMe(), puis enregistre le webhook.
     * Retourne le CanalEntreprise créé/mis à jour.
     */
    public function connecter(int $entrepriseId, string $token): CanalEntreprise
    {
        $token = trim($token);

        $me = $this->call($token, 'getMe');

        if (! ($me['ok'] ?? false)) {
            throw new RuntimeException("Token Telegram invalide ou rejeté par l'API.");
        }

        $canal = CanalEntreprise::updateOrCreate(
            ['entreprise_id' => $entrepriseId, 'type' => 'telegram'],
            [
                'token' => $token,
                'bot_username' => $me['result']['username'] ?? null,
                'bot_id' => (string) ($me['result']['id'] ?? ''),
                'webhook_secret' => Str::random(40),
                'actif' => false,
            ]
        );

        $webhookUrl = rtrim(config('app.url'), '/')
            . "/api/webhooks/telegram/{$canal->entreprise_id}/{$canal->webhook_secret}";

        $result = $this->call($token, 'setWebhook', [
            'url' => $webhookUrl,
            // Telegram renverra ce header sur chaque appel webhook,
            // on le revérifie côté controller pour se prémunir des faux appels
            'secret_token' => $canal->webhook_secret,
            'allowed_updates' => ['message'],
        ]);

        if (! ($result['ok'] ?? false)) {
            throw new RuntimeException("Impossible d'enregistrer le webhook Telegram : "
                . ($result['description'] ?? 'erreur inconnue'));
        }

        $canal->update(['actif' => true, 'connecte_le' => now()]);

        return $canal;
    }

    public function deconnecter(CanalEntreprise $canal): void
    {
        $this->call($canal->token, 'deleteWebhook');
        $canal->update(['actif' => false]);
    }

    public function envoyerMessage(CanalEntreprise $canal, string|int $chatId, string $texte): array
    {
        return $this->call($canal->token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => $texte,
            'parse_mode' => 'HTML',
        ]);
    }

    private function call(string $token, string $method, array $params = []): array
    {
        $response = Http::asJson()
            ->timeout(15)
            ->post(self::API_BASE . $token . '/' . $method, $params);

        return $response->json() ?? ['ok' => false, 'description' => 'Réponse invalide'];
    }
}
