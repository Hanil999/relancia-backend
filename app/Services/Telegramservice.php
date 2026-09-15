<?php

namespace App\Services;

use App\Models\CanalEntreprise;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public function envoyerMessage(CanalEntreprise $canal, string|int $chatId, string $texte, ?string $boutonUrl = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $texte,
        ];

        // Si un lien est fourni, il est présenté dans un bouton cliquable
        // plutôt qu'une longue URL collée dans le texte.
        if ($boutonUrl) {
            $params['reply_markup'] = [
                'inline_keyboard' => [[
                    ['text' => '💳 Payer par carte', 'url' => $boutonUrl],
                ]],
            ];
        }

        $result = $this->call($canal->token, 'sendMessage', $params);

        if (! ($result['ok'] ?? false)) {
            Log::error('Telegram sendMessage refuse', [
                'chat_id' => $chatId,
                'description' => $result['description'] ?? 'erreur inconnue',
            ]);

            throw new RuntimeException(
                'Telegram sendMessage: ' . ($result['description'] ?? 'erreur inconnue')
            );
        }

        return $result;
    }

    /**
     * Télécharge une photo reçue via Telegram.
     * Retourne le chemin local du fichier ou null en cas d'échec.
     */
    public function telechargerPhoto(string $token, string $fileId): ?string
    {
        $fileInfo = $this->call($token, 'getFile', ['file_id' => $fileId]);

        if (! ($fileInfo['ok'] ?? false)) {
            Log::warning('Telegram getFile echoue', ['file_id' => $fileId]);
            return null;
        }

        $filePath = $fileInfo['result']['file_path'] ?? null;
        if (! $filePath) {
            return null;
        }

        $url = "https://api.telegram.org/file/bot{$token}/{$filePath}";

        $response = Http::timeout(30)->get($url);

        if (! $response->successful()) {
            return null;
        }

        $ext = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg';
        $filename = 'preuve_' . Str::random(20) . ".{$ext}";
        $destPath = storage_path("app/public/paiements/{$filename}");

        \Illuminate\Support\Facades\File::makeDirectory(dirname($destPath), 0755, true, true);
        file_put_contents($destPath, $response->body());

        return "paiements/{$filename}";
    }

    private function call(string $token, string $method, array $params = []): array
    {
        try {
            $response = Http::asJson()
                ->timeout(15)
                ->post(self::API_BASE . $token . '/' . $method, $params);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Telegram API injoignable', ['method' => $method, 'erreur' => $e->getMessage()]);
            throw new RuntimeException('Telegram API injoignable: ' . $e->getMessage());
        }

        return $response->json() ?? ['ok' => false, 'description' => 'Réponse invalide'];
    }
}
