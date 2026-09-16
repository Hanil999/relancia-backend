<?php

namespace App\Services;

use App\Models\CanalEntreprise;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class FacebookMessengerService
{
    private const GRAPH_API = 'https://graph.facebook.com/v19.0';

    /**
     * Vérifie le token de la page, récupère son nom, puis crée/met à jour le canal.
     */
    public function connecter(int $entrepriseId, string $pageId, string $pageToken, ?string $appSecret = null): CanalEntreprise
    {
        $pageId = trim($pageId);
        $pageToken = trim($pageToken);
        $appSecret = $appSecret !== null ? trim($appSecret) : null;

        $response = Http::withToken($pageToken)
            ->timeout(15)
            ->get(self::GRAPH_API . '/' . $pageId . '?fields=name,id');

        $data = $response->json();

        if (! $response->successful() || empty($data['id'])) {
            throw new RuntimeException(
                "Page ID ou Access Token invalide. "
                . ($data['error']['message'] ?? 'Reponse inattendue de l\'API Meta.')
            );
        }

        $existant = CanalEntreprise::where('entreprise_id', $entrepriseId)
            ->where('type', 'messenger')
            ->where('bot_id', $pageId)
            ->first();

        $appSecret = $appSecret ?: ($existant->app_secret ?? null);
        $webhookSecret = $existant ? $existant->webhook_secret : Str::random(40);

        $canal = CanalEntreprise::updateOrCreate(
            ['entreprise_id' => $entrepriseId, 'type' => 'messenger', 'bot_id' => $pageId],
            [
                'token' => $pageToken,
                'bot_username' => $data['name'] ?? null,
                'webhook_secret' => $webhookSecret,
                'app_secret' => $appSecret ?: null,
                'actif' => false,
            ]
        );

        $canal->update(['actif' => true, 'connecte_le' => now()]);

        return $canal;
    }

    public function deconnecter(CanalEntreprise $canal): void
    {
        $this->desabonnerWebhook($canal);
        $canal->update(['actif' => false]);
    }

    /**
     * Abonne la page aux webhooks de l'application Meta (Messenger).
     * Sans cet abonnement, Meta ne nous livre pas les messages entrants.
     */
    public function abonnerWebhook(CanalEntreprise $canal): array
    {
        $response = Http::withToken($canal->token)
            ->timeout(15)
            ->post(self::GRAPH_API . '/' . $canal->bot_id . '/subscribed_apps');

        $data = $response->json() ?? [];

        if (! $response->successful()) {
            return [
                'ok' => false,
                'message' => $data['error']['message'] ?? "Meta a refusé l'abonnement du webhook.",
            ];
        }

        $ok = ($data['success'] ?? false) === true;

        return [
            'ok' => $ok,
            'message' => $ok ? null : ($data['error']['message'] ?? "Meta n'a pas confirmé l'abonnement."),
        ];
    }

    /** Désabonne la page (meilleur effort, en silence). */
    public function desabonnerWebhook(CanalEntreprise $canal): void
    {
        try {
            Http::withToken($canal->token)
                ->timeout(15)
                ->delete(self::GRAPH_API . '/' . $canal->bot_id . '/subscribed_apps');
        } catch (\Throwable $e) {
            // Meilleur effort : on ne bloque pas la déconnexion.
        }
    }

    /**
     * Envoie un texte au client Messenger (PSID). Si un lien de paiement est
     * fourni, on envoie en plus un template « generic » avec un bouton web_url.
     */
    public function envoyerMessage(CanalEntreprise $canal, string $psid, string $texte, ?string $boutonUrl = null): array
    {
        $texte = mb_substr($texte, 0, 640);

        $result = $this->envoyer($canal, $psid, ['text' => $texte]);

        if (! ($result['ok'] ?? false)) {
            return $result;
        }

        if ($boutonUrl) {
            $this->envoyer($canal, $psid, [
                'attachment' => [
                    'type' => 'template',
                    'payload' => [
                        'template_type' => 'generic',
                        'elements' => [[
                            'title' => '💳 Paiement sécurisé',
                            'subtitle' => 'Touchez le bouton pour régler par carte.',
                            'default_action' => [
                                'type' => 'web_url',
                                'url' => $boutonUrl,
                            ],
                            'buttons' => [[
                                'type' => 'web_url',
                                'url' => $boutonUrl,
                                'title' => '💳 Payer par carte',
                            ]],
                        ]],
                    ],
                ],
            ]);
        }

        return $result;
    }

    private function envoyer(CanalEntreprise $canal, string $psid, array $message): array
    {
        $response = Http::withToken($canal->token)
            ->timeout(30)
            ->connectTimeout(15)
            ->post(self::GRAPH_API . '/me/messages', [
                'recipient' => ['id' => $psid],
                'message' => $message,
            ]);

        $data = $response->json();

        if (! $response->successful()) {
            Log::warning('Messenger sendMessage refuse', [
                'page_id' => $canal->bot_id,
                'erreur' => $data['error']['message'] ?? 'Erreur inconnue',
            ]);

            return [
                'ok' => false,
                'error' => $data['error']['message'] ?? 'Erreur inconnue',
            ];
        }

        return [
            'ok' => true,
            'message_id' => $data['message_id'] ?? null,
            'recipient_id' => $data['recipient_id'] ?? null,
        ];
    }

    /**
     * Récupère le profil public d'un PSID (nom) via l'API Messenger.
     * Retourne null si indisponible.
     */
    public function profilUser(string $token, string $psid): ?string
    {
        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->get(self::GRAPH_API . '/' . $psid . '?fields=first_name,last_name,profile_pic');

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();

            return trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Télécharge l'image (preuve de paiement) reçue via Messenger.
     * Retourne le chemin relatif ou null en cas d'échec.
     */
    public function telechargerImage(string $token, string $url): ?string
    {
        // L'URL fournie par Messenger accepte le token de page en paramètre.
        $image = Http::timeout(30)->get($url, ['access_token' => $token]);

        if (! $image->successful()) {
            $image = Http::withToken($token)->timeout(30)->get($url);
        }

        if (! $image->successful()) {
            Log::warning('Messenger telechargement image echoue', ['url' => mb_substr($url, 0, 120)]);
            return null;
        }

        $contentType = $image->header('Content-Type') ?? '';
        $ext = match (true) {
            str_contains($contentType, 'image/png') => 'png',
            str_contains($contentType, 'image/webp') => 'webp',
            str_contains($contentType, 'image/gif') => 'gif',
            str_contains($contentType, 'image/bmp') => 'bmp',
            default => 'jpg',
        };

        $filename = 'preuve_' . Str::random(20) . ".{$ext}";
        $destPath = storage_path("app/public/paiements/{$filename}");

        \Illuminate\Support\Facades\File::makeDirectory(dirname($destPath), 0755, true, true);
        file_put_contents($destPath, $image->body());

        return "paiements/{$filename}";
    }

    /**
     * Vérifie la signature HMAC X-Hub-Signature-256 signée avec l'App Secret.
     */
    public function verifierSignature(string $appSecret, string $rawBody, string $signature): bool
    {
        if (empty($appSecret) || empty($signature)) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);

        return hash_equals($expected, $signature);
    }
}