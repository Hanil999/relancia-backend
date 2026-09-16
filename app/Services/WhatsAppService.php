<?php

namespace App\Services;

use App\Models\CanalEntreprise;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class WhatsAppService
{
    private const GRAPH_API = 'https://graph.facebook.com/v19.0';

    /**
     * Verify the access token, fetch the phone number info, and store the canal.
     */
    public function connecter(int $entrepriseId, string $phoneNumberId, string $accessToken, ?string $appSecret = null): CanalEntreprise
    {
        $phoneNumberId = trim($phoneNumberId);
        $accessToken = trim($accessToken);
        $appSecret = $appSecret !== null ? trim($appSecret) : null;

        // Verify the token by fetching the phone number info
        $response = Http::withToken($accessToken)
            ->timeout(15)
            ->get(self::GRAPH_API . '/' . $phoneNumberId);

        $data = $response->json();

        if (! $response->successful() || empty($data['id'])) {
            throw new RuntimeException(
                "Phone Number ID ou Access Token invalide. "
                . ($data['error']['message'] ?? 'Reponse inattendue de l\'API Meta.')
            );
        }

        $displayPhoneNumber = $data['display_phone_number'] ?? null;
        $verifiedName = $data['verified_name'] ?? null;

        // Conserver l'App Secret déjà renseigné lors d'une reconnexion sans champ
        $existant = CanalEntreprise::where('entreprise_id', $entrepriseId)->where('type', 'whatsapp')->first();
        $appSecret = $appSecret ?: ($existant->app_secret ?? null);

        $canal = CanalEntreprise::updateOrCreate(
            ['entreprise_id' => $entrepriseId, 'type' => 'whatsapp'],
            [
                'token' => $accessToken,
                'bot_username' => $verifiedName ?: $displayPhoneNumber,
                'bot_id' => $phoneNumberId,
                'webhook_secret' => Str::random(40),
                'app_secret' => $appSecret ?: null,
                'actif' => false,
            ]
        );

        $canal->update(['actif' => true, 'connecte_le' => now()]);

        return $canal;
    }

    public function deconnecter(CanalEntreprise $canal): void
    {
        $canal->update(['actif' => false]);
    }

    /**
     * Abonne le numéro au webhook de l'application Meta afin que Meta nous
     * livre les messages entrants. Sans cet abonnement, le bot ne reçoit rien.
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

    /** Désabonne le numéro du webhook (meilleur effort, en silence). */
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
     * Send a text message via WhatsApp Cloud API.
     *
     * @param  CanalEntreprise  $canal
     * @param  string  $to  Phone number in international format (e.g. "261340000000")
     * @param  string  $texte
     */
    public function envoyerMessage(CanalEntreprise $canal, string $to, string $texte, ?string $boutonUrl = null): array
    {
        $phoneNumberId = $canal->bot_id;
        $accessToken = $canal->token;

        // Si un lien est fourni, il est présenté dans un bouton cliquable
        // (interactive) plutôt qu'une longue URL collée dans le texte.
        if ($boutonUrl) {
            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'button',
                    'header' => ['type' => 'text', 'text' => '💳 Paiement sécurisé'],
                    'body' => ['text' => mb_substr($texte, 0, 1024)],
                    'action' => [
                        'buttons' => [[
                            'type' => 'url',
                            'text' => '💳 Payer par carte',
                            'url' => $boutonUrl,
                            'example' => [$boutonUrl],
                        ]],
                    ],
                ],
            ];
        } else {
            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $texte],
            ];
        }

        $response = Http::withToken($accessToken)
            ->timeout(30)
            ->connectTimeout(15)
            ->post(self::GRAPH_API . '/' . $phoneNumberId . '/messages', $payload);

        $data = $response->json();

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error' => $data['error']['message'] ?? 'Erreur inconnue',
            ];
        }

        return ['ok' => true, 'message_id' => $data['messages'][0]['id'] ?? null];
    }

    /**
     * Télécharge une image reçue via l'API Cloud WhatsApp (proof de paiement).
     * Retourne le chemin local relatif (dans storage/app/public/paiements)
     * ou null en cas d'échec.
     */
    public function telechargerImage(string $token, string $phoneNumberId, string $mediaId): ?string
    {
        // 1. Récupérer l'URL offloadée du média
        $info = Http::withToken($token)
            ->timeout(30)
            ->connectTimeout(15)
            ->get(self::GRAPH_API . '/' . $mediaId);

        if (! $info->successful()) {
            Log::warning('WhatsApp getMedia echoue', ['media_id' => $mediaId]);
            return null;
        }

        $mediaUrl = $info->json('url');
        $mimeType = $info->json('mime_type');

        if (! $mediaUrl) {
            return null;
        }

        // 2. Télécharger le binaire (le token est accepté en header ou en query)
        $image = Http::withToken($token)->timeout(30)->get($mediaUrl);

        if (! $image->successful()) {
            $image = Http::timeout(30)->get($mediaUrl, ['access_token' => $token]);
        }

        if (! $image->successful()) {
            Log::warning('WhatsApp telechargement image echoue', ['media_id' => $mediaId]);
            return null;
        }

        $ext = match ($mimeType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
            default => 'jpg',
        };

        $filename = 'preuve_' . Str::random(20) . ".{$ext}";
        $destPath = storage_path("app/public/paiements/{$filename}");

        \Illuminate\Support\Facades\File::makeDirectory(dirname($destPath), 0755, true, true);
        file_put_contents($destPath, $image->body());

        return "paiements/{$filename}";
    }

    /**
     * Verify the X-Hub-Signature-256 HMAC signature sent by Meta.
     * Meta signe le corps de la requête avec l'App Secret de l'application.
     */
    public function verifierSignature(CanalEntreprise $canal, string $rawBody, string $signature): bool
    {
        if (empty($signature)) {
            return false;
        }

        $appSecret = $canal->app_secret;

        if (empty($appSecret)) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);

        return hash_equals($expected, $signature);
    }
}
