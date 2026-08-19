<?php

namespace App\Services;

use App\Models\CanalEntreprise;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class WhatsAppService
{
    private const GRAPH_API = 'https://graph.facebook.com/v19.0';

    /**
     * Verify the access token, fetch the phone number info, and store the canal.
     */
    public function connecter(int $entrepriseId, string $phoneNumberId, string $accessToken): CanalEntreprise
    {
        $phoneNumberId = trim($phoneNumberId);
        $accessToken = trim($accessToken);

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

        $canal = CanalEntreprise::updateOrCreate(
            ['entreprise_id' => $entrepriseId, 'type' => 'whatsapp'],
            [
                'token' => $accessToken,
                'bot_username' => $verifiedName ?: $displayPhoneNumber,
                'bot_id' => $phoneNumberId,
                'webhook_secret' => Str::random(40),
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
     * Send a text message via WhatsApp Cloud API.
     *
     * @param  CanalEntreprise  $canal
     * @param  string  $to  Phone number in international format (e.g. "261340000000")
     * @param  string  $texte
     */
    public function envoyerMessage(CanalEntreprise $canal, string $to, string $texte): array
    {
        $phoneNumberId = $canal->bot_id;

        $response = Http::withToken($canal->token)
            ->timeout(15)
            ->post(self::GRAPH_API . '/' . $phoneNumberId . '/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $texte],
            ]);

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
     * Verify the X-Hub-Signature-256 HMAC signature sent by Meta.
     */
    public function verifierSignature(CanalEntreprise $canal, string $rawBody, string $signature): bool
    {
        if (empty($signature)) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $canal->webhook_secret);

        return hash_equals($expected, $signature);
    }
}
