<?php

namespace App\Http\Controllers;

use App\Models\CanalEntreprise;
use App\Models\Client;
use App\Models\Commande;
use App\Models\Entreprise;
use App\Models\MessageCanal;
use App\Services\FacebookMessengerService;
use App\Services\NotificationService;
use App\Services\OcrService;
use App\Services\PaiementService;
use App\Services\ReponseAutomatiqueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FacebookMessengerController extends Controller
{
    public function __construct(
        private FacebookMessengerService $messenger,
        private ReponseAutomatiqueService $reponses,
        private NotificationService $notifications,
        private PaiementService $paiements,
        private OcrService $ocr,
    ) {}

    /** GET /entreprises/{entreprise}/canaux/messenger */
    public function show(Entreprise $entreprise)
    {
        $this->authorize('update', $entreprise);

        $canal = $entreprise->canaux()->where('type', 'messenger')->first();

        if (! $canal) {
            return response()->json(['connecte' => false]);
        }

        return response()->json([
            'connecte' => $canal->actif,
            'bot_username' => $canal->bot_username,
            'page_id' => $canal->bot_id,
            'connecte_le' => $canal->connecte_le,
            'webhook_callback_url' => rtrim(config('app.url'), '/')
                . "/api/webhooks/messenger/{$entreprise->id}/{$canal->webhook_secret}",
            'webhook_verify_token' => $canal->webhook_secret,
        ]);
    }

    /** POST /entreprises/{entreprise}/canaux/messenger */
    public function store(Request $request, Entreprise $entreprise)
    {
        $this->authorize('update', $entreprise);

        $data = $request->validate([
            'page_id' => ['required', 'string', 'min:4'],
            'access_token' => ['required', 'string', 'min:20'],
            'app_secret' => ['nullable', 'string', 'min:8'],
        ]);

        try {
            $canal = $this->messenger->connecter(
                $entreprise->id,
                $data['page_id'],
                $data['access_token'],
                $data['app_secret'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $webhook = $this->messenger->abonnerWebhook($canal);

        return response()->json([
            'connecte' => true,
            'bot_username' => $canal->bot_username,
            'webhook_callback_url' => rtrim(config('app.url'), '/')
                . "/api/webhooks/messenger/{$entreprise->id}/{$canal->webhook_secret}",
            'webhook_verify_token' => $canal->webhook_secret,
            'webhook_subscribed' => $webhook['ok'],
            'webhook_message' => $webhook['message'],
        ], 201);
    }

    /** DELETE /entreprises/{entreprise}/canaux/messenger */
    public function destroy(Entreprise $entreprise)
    {
        $this->authorize('update', $entreprise);

        $canal = $entreprise->canaux()->where('type', 'messenger')->first();

        if ($canal) {
            $this->messenger->deconnecter($canal);
        }

        return response()->json(['connecte' => false]);
    }

    /** POST /entreprises/{entreprise}/canaux/messenger/abonner */
    public function abonner(Entreprise $entreprise)
    {
        $this->authorize('update', $entreprise);

        $canal = $entreprise->canaux()->where('type', 'messenger')->where('actif', true)->first();

        if (! $canal) {
            return response()->json(['message' => 'Messenger non connecté'], 404);
        }

        $webhook = $this->messenger->abonnerWebhook($canal);

        return response()->json([
            'webhook_subscribed' => $webhook['ok'],
            'webhook_message' => $webhook['message'],
        ], $webhook['ok'] ? 200 : 422);
    }

    /** GET /entreprises/{entreprise}/canaux/messenger/conversations */
    public function conversations(Entreprise $entreprise)
    {
        $this->authorize('update', $entreprise);

        $derniers = MessageCanal::where('entreprise_id', $entreprise->id)
            ->where('canal', 'Messenger')
            ->with('client:id,nom')
            ->orderByDesc('created_at')
            ->get()
            ->unique('client_id')
            ->values()
            ->map(fn (MessageCanal $m) => [
                'client_id' => $m->client_id,
                'nom' => $m->client->nom,
                'conversation_id' => $m->conversation_id,
                'dernier_message' => $m->texte,
                'direction' => $m->direction,
                'date' => $m->created_at,
            ]);

        $clientIds = $derniers->pluck('client_id')->values()->all();

        $dernieresCommandes = Commande::where('entreprise_id', $entreprise->id)
            ->whereIn('client_id', $clientIds)
            ->whereIn('canal', ['Telegram', 'WhatsApp', 'Messenger', 'Instagram'])
            ->latest()
            ->get()
            ->keyBy('client_id');

        $derniers = $derniers->map(function ($conv) use ($dernieresCommandes) {
            $cmd = $dernieresCommandes->get($conv['client_id']);
            $conv['derniere_commande'] = $cmd ? [
                'id' => $cmd->id,
                'numero' => $cmd->numero,
                'montant_total' => (float) $cmd->montant_total,
                'statut' => $cmd->statut,
                'statut_label' => $cmd->statut_label,
                'creee_le' => $cmd->created_at,
            ] : null;

            return $conv;
        });

        return response()->json($derniers);
    }

    /** GET /entreprises/{entreprise}/canaux/messenger/conversations/{client}/messages */
    public function messages(Entreprise $entreprise, Client $client)
    {
        $this->authorize('update', $entreprise);

        $messages = MessageCanal::where('entreprise_id', $entreprise->id)
            ->where('canal', 'Messenger')
            ->where('client_id', $client->id)
            ->orderBy('created_at')
            ->get(['id', 'direction', 'texte', 'media_url', 'media_type', 'source', 'created_at']);

        $commandes = Commande::where('entreprise_id', $entreprise->id)
            ->where('client_id', $client->id)
            ->where('canal', 'Messenger')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($cmd) => [
                'id' => $cmd->id,
                'numero' => $cmd->numero,
                'montant_total' => (float) $cmd->montant_total,
                'statut' => $cmd->statut,
                'statut_label' => $cmd->statut_label,
                'creee_le' => $cmd->created_at,
                'message_origine' => $cmd->message_origine,
            ]);

        return response()->json([
            'messages' => $messages,
            'commandes' => $commandes,
        ]);
    }

    /** POST /entreprises/{entreprise}/canaux/messenger/envoyer */
    public function envoyer(Request $request, Entreprise $entreprise)
    {
        $this->authorize('update', $entreprise);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'texte' => ['required', 'string'],
        ]);

        $canal = $entreprise->canaux()->where('type', 'messenger')->where('actif', true)->first();

        if (! $canal) {
            return response()->json(['message' => 'Messenger non connecté'], 422);
        }

        $dernierMessage = MessageCanal::where('entreprise_id', $entreprise->id)
            ->where('client_id', $data['client_id'])
            ->where('canal', 'Messenger')
            ->latest()
            ->first();

        if (! $dernierMessage) {
            return response()->json(['message' => 'Conversation introuvable'], 404);
        }

        $result = $this->messenger->envoyerMessage($canal, $dernierMessage->conversation_id, $data['texte']);

        if (! ($result['ok'] ?? false)) {
            return response()->json(['message' => "Échec de l'envoi Messenger"], 502);
        }

        $message = MessageCanal::create([
            'entreprise_id' => $entreprise->id,
            'client_id' => $data['client_id'],
            'canal' => 'Messenger',
            'direction' => 'sortant',
            'texte' => $data['texte'],
            'conversation_id' => $dernierMessage->conversation_id,
            'source' => 'manuel',
        ]);

        return response()->json($message, 201);
    }

    /** GET /entreprises/{entreprise}/canaux/messenger/produits */
    public function produits(Entreprise $entreprise)
    {
        $this->authorize('update', $entreprise);

        $produits = $entreprise->produits()
            ->select('id', 'nom', 'prix', 'stock')
            ->get();

        return response()->json($produits);
    }

    /**
     * GET /webhooks/messenger/{entreprise}/{secret} — Meta webhook verification (challenge).
     */
    public function challenge(Request $request, int $entrepriseId, string $secret)
    {
        $canal = CanalEntreprise::where('entreprise_id', $entrepriseId)
            ->where('type', 'messenger')
            ->where('webhook_secret', $secret)
            ->first();

        if (! $canal) {
            return response('Forbidden', 403);
        }

        $queryParams = [];
        parse_str($request->getQueryString() ?: '', $queryParams);

        $mode = $queryParams['hub.mode'] ?? ($queryParams['hub_mode'] ?? null);
        $verifyToken = $queryParams['hub.verify_token'] ?? ($queryParams['hub_verify_token'] ?? null);
        $challenge = $queryParams['hub.challenge'] ?? ($queryParams['hub_challenge'] ?? null);

        if ($mode === 'subscribe' && $verifyToken === $canal->webhook_secret) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    /**
     * POST /webhooks/messenger/{entreprise}/{secret} — PUBLIC, appelé par Meta.
     */
    public function webhook(Request $request, int $entrepriseId, string $secret)
    {
        $canal = CanalEntreprise::where('entreprise_id', $entrepriseId)
            ->where('type', 'messenger')
            ->where('webhook_secret', $secret)
            ->first();

        if (! $canal || ! $canal->actif) {
            Log::warning('Webhook Messenger rejeté', ['entreprise_id' => $entrepriseId]);
            return response()->json(['ok' => false], 403);
        }

        $signature = $request->header('X-Hub-Signature-256');

        if ($signature && $canal->app_secret) {
            $rawBody = $request->getContent();
            if (! $this->messenger->verifierSignature($canal->app_secret, $rawBody, $signature)) {
                Log::warning('Signature Messenger invalide', ['entreprise_id' => $entrepriseId]);
                return response()->json(['ok' => false], 403);
            }
        } else {
            Log::debug('Webhook Messenger sans contrôle HMAC (app_secret non configuré)', [
                'entreprise_id' => $entrepriseId,
                'signature' => $signature ? 'presente' : 'absente',
            ]);
        }

        $payload = $request->json()->all();
        $entries = $payload['entry'] ?? [];

        foreach ($entries as $entry) {
            $messagingEvents = $entry['messaging'] ?? [];

            foreach ($messagingEvents as $event) {
                $message = $event['message'] ?? null;
                $senderId = $event['sender']['id'] ?? null;

                if (! $message || ! $senderId) {
                    continue;
                }

                $this->traiterMessage($canal, $entrepriseId, $senderId, $message, $payload);
            }
        }

        return response()->json(['ok' => true]);
    }

    private function traiterMessage(CanalEntreprise $canal, int $entrepriseId, string $psid, array $message, array $payload): void
    {
        $entreprise = Entreprise::find($entrepriseId);
        if (! $entreprise) {
            return;
        }

        $nomClient = $this->messenger->profilUser($canal->token, $psid)
            ?: 'Client Facebook';

        $client = Client::firstOrCreate(
            ['identifiant_externe' => $psid],
            ['nom' => $nomClient, 'canal_prefere' => 'Messenger']
        );

        $entreprise->clients()->syncWithoutDetaching([
            $client->id => [
                'plateforme_sociale' => 'facebook',
                'identifiant_social' => $psid,
                'premier_contact_le' => now(),
            ],
        ]);

        // --- PHOTO (preuve de paiement) ---
        if (! empty($message['attachments'])) {
            foreach ($message['attachments'] as $attachement) {
                if (($attachement['type'] ?? '') === 'image' && ! empty($attachement['payload']['url'])) {
                    $this->traiterPhotoPreuve($canal, $entreprise, $client, $psid, $attachement['payload']['url'], $message['mid'] ?? null);
                    return;
                }
            }
        }

        $texte = $message['text'] ?? null;
        if (! $texte) {
            return;
        }

        MessageCanal::create([
            'entreprise_id' => $entreprise->id,
            'client_id' => $client->id,
            'canal' => 'Messenger',
            'direction' => 'entrant',
            'texte' => $texte,
            'conversation_id' => $psid,
            'source' => 'manuel',
        ]);

        try {
            $this->notifications->messageRecu($entreprise, $client, $texte, 'Messenger');
        } catch (\Throwable $e) {
            Log::warning('Notification message Messenger reçu échouée', [
                'entreprise_id' => $entrepriseId,
                'erreur' => $e->getMessage(),
            ]);
        }

        try {
            $reponse = $this->reponses->repondre($entreprise, $client, $texte, 'Messenger');

            $result = $this->messenger->envoyerMessage(
                $canal,
                $psid,
                $reponse['message'],
                $reponse['lien_paiement'] ?? null,
            );

            MessageCanal::create([
                'entreprise_id' => $entreprise->id,
                'client_id' => $client->id,
                'canal' => 'Messenger',
                'direction' => 'sortant',
                'texte' => $reponse['message'],
                'conversation_id' => $psid,
                'source' => 'auto',
                'statut_envoi' => ($result['ok'] ?? false) ? null : 'echec',
            ]);

            if (! ($result['ok'] ?? false)) {
                Log::warning('Réponse auto Messenger non livrée par Meta', [
                    'entreprise_id' => $entrepriseId,
                    'psid' => $psid,
                    'erreur' => $result['error'] ?? 'inconnue',
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Réponse auto Messenger échouée', [
                'entreprise_id' => $entrepriseId,
                'psid' => $psid,
                'erreur' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Traite une photo reçue : OCR → recherche commande en_attente → enregistrement paiement auto.
     */
    private function traiterPhotoPreuve(
        CanalEntreprise $canal,
        Entreprise $entreprise,
        Client $client,
        string $psid,
        string $mediaUrl,
        ?string $messageMid,
    ): void {
        $msgPhoto = MessageCanal::create([
            'entreprise_id' => $entreprise->id,
            'client_id' => $client->id,
            'canal' => 'Messenger',
            'direction' => 'entrant',
            'texte' => '[Photo — preuve de paiement]',
            'conversation_id' => $psid,
            'source' => 'manuel',
        ]);

        $cheminPhoto = $this->messenger->telechargerImage($canal->token, $mediaUrl);

        if (! $cheminPhoto) {
            $this->envoyerReponse($canal, $psid, $entreprise, $client, 'Image non reçue. Veuillez réessayer ou envoyer une capture d\'écran plus nette.');
            return;
        }

        $mediaUrlFinal = url('/storage/' . $cheminPhoto);
        $msgPhoto->update([
            'texte' => '[Photo — preuve de paiement]',
            'media_url' => $mediaUrlFinal,
            'media_type' => 'image',
        ]);

        $derniereCommande = Commande::where('entreprise_id', $entreprise->id)
            ->where('client_id', $client->id)
            ->whereIn('statut', ['en_attente'])
            ->latest()
            ->first();

        if (! $derniereCommande) {
            $this->envoyerReponse($canal, $psid, $entreprise, $client, 'Aucune commande en attente de paiement. Si vous souhaitez passer une commande, envoyez votre commande en texte.');
            return;
        }

        $uploadedFile = new \Illuminate\Http\UploadedFile(
            storage_path("app/public/{$cheminPhoto}"),
            basename($cheminPhoto),
            mime_content_type(storage_path("app/public/{$cheminPhoto}")),
            null,
            true
        );

        $pct = $entreprise->acompte_pct ?? 50;
        $montantAttendu = (float) $derniereCommande->montant_total * $pct / 100;
        $montantTotal = (float) $derniereCommande->montant_total;

        $verification = $this->ocr->verifierMontant($uploadedFile, $montantAttendu);

        Log::info('OCR Photo preuve Messenger', [
            'commande_id' => $derniereCommande->id,
            'montant_attendu_acompte' => $montantAttendu,
            'montant_total' => $montantTotal,
            'texte_ocr' => $verification['texte'] ?? '',
            'montants_trouves' => $verification['montants_trouves'] ?? [],
            'match' => $verification['match'] ?? false,
            'montant_detecte' => $verification['montant_detecte'] ?? null,
            'chemin_photo' => $cheminPhoto,
        ]);

        $montants = $verification['montants_trouves'] ?? [];
        $matchAcompte = false;
        $matchTotal = false;

        foreach ($montants as $m) {
            if (abs($m - $montantAttendu) < 1) $matchAcompte = true;
            if (abs($m - $montantTotal) < 1) $matchTotal = true;
        }

        if (! empty($verification['texte']) && ($matchAcompte || $matchTotal)) {
            $montantAPayer = $matchTotal ? $montantTotal : $montantAttendu;

            $paiement = $this->paiements->enregistrerPaiement(
                $derniereCommande,
                $montantAPayer,
                'mobile_money',
                'Messenger — preuve photo',
                $uploadedFile,
                "messenger:{$psid}:{$messageMid}",
            );

            $montantFmt = number_format($montantAPayer, 0, ',', ' ');
            $reste = max(0, $montantTotal - (float) $derniereCommande->fresh()->montant_paye);

            $reponse = "Paiement de {$montantFmt} Ar enregistré pour la commande {$derniereCommande->numero}.";
            if ($reste > 0) {
                $resteFmt = number_format($reste, 0, ',', ' ');
                $reponse .= " Il reste {$resteFmt} Ar à payer.";
            } else {
                $reponse .= " Commande intégralement payée !";
            }

            $this->envoyerReponse($canal, $psid, $entreprise, $client, $reponse);
        } else {
            $detecteStr = ! empty($montants)
                ? 'Montant(s) détecté(s) : ' . implode(', ', array_map(fn ($m) => number_format($m, 0, ',', ' ') . ' Ar', $montants))
                : 'Aucun montant détecté sur l\'image.';

            $acompteFmt = number_format($montantAttendu, 0, ',', ' ');
            $totalFmt = number_format($montantTotal, 0, ',', ' ');

            $this->envoyerReponse(
                $canal,
                $psid,
                $entreprise,
                $client,
                "Impossible de vérifier le montant automatiquement. {$detecteStr}\n"
                . "Montant attendu : {$acompteFmt} Ar (acompte) ou {$totalFmt} Ar (total).\n"
                . "Veuillez effectuer le paiement par Mobile Money en incluant le numéro de commande dans la référence."
            );
        }
    }

    private function envoyerReponse(
        CanalEntreprise $canal,
        string $psid,
        Entreprise $entreprise,
        Client $client,
        string $texte,
    ): void {
        try {
            $result = $this->messenger->envoyerMessage($canal, $psid, $texte);

            MessageCanal::create([
                'entreprise_id' => $entreprise->id,
                'client_id' => $client->id,
                'canal' => 'Messenger',
                'direction' => 'sortant',
                'texte' => $texte,
                'conversation_id' => $psid,
                'source' => 'auto',
                'statut_envoi' => ($result['ok'] ?? false) ? null : 'echec',
            ]);

            if (! ($result['ok'] ?? false)) {
                Log::warning('Envoi Messenger non livré par Meta', [
                    'psid' => $psid,
                    'erreur' => $result['error'] ?? 'inconnue',
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Envoi Messenger échoué', ['psid' => $psid, 'erreur' => $e->getMessage()]);
        }
    }
}