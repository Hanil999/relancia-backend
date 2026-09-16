<?php

namespace App\Http\Controllers;

use App\Models\CanalEntreprise;
use App\Models\Client;
use App\Models\Commande;
use App\Models\Entreprise;
use App\Models\MessageCanal;
use App\Services\NotificationService;
use App\Services\OcrService;
use App\Services\PaiementService;
use App\Services\ReponseAutomatiqueService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class TelegramController extends Controller
{
    public function __construct(
        private TelegramService $telegram,
        private ReponseAutomatiqueService $reponses,
        private NotificationService $notifications,
        private PaiementService $paiements,
        private OcrService $ocr,
    ) {}

    /** GET /entreprises/{entreprise}/canaux/telegram */
    public function show(Entreprise $entreprise)
    {
        $this->authorize('update', $entreprise);

        $canal = $entreprise->canaux()->where('type', 'telegram')->first();

        if (! $canal) {
            return response()->json(['connecte' => false]);
        }

        return response()->json([
            'connecte' => $canal->actif,
            'bot_username' => $canal->bot_username,
            'connecte_le' => $canal->connecte_le,
        ]);
    }

    /** POST /entreprises/{entreprise}/canaux/telegram */
    public function store(Request $request, Entreprise $entreprise)
    {
        $this->authorize('update', $entreprise);

        $data = $request->validate(['token' => ['required', 'string', 'min:20']]);

        try {
            $canal = $this->telegram->connecter($entreprise->id, $data['token']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'connecte' => true,
            'bot_username' => $canal->bot_username,
        ], 201);
    }

    /** DELETE /entreprises/{entreprise}/canaux/telegram */
    public function destroy(Entreprise $entreprise)
    {
        $this->authorize('update', $entreprise);

        $canal = $entreprise->canaux()->where('type', 'telegram')->first();

        if ($canal) {
            $this->telegram->deconnecter($canal);
        }

        return response()->json(['connecte' => false]);
    }

    /**
     * GET /entreprises/{entreprise}/canaux/telegram/conversations
     * Liste des clients ayant écrit sur Telegram, avec le dernier message.
     */
    public function conversations(Entreprise $entreprise)
    {
        $this->authorize('update', $entreprise);

        $derniers = MessageCanal::where('entreprise_id', $entreprise->id)
            ->where('canal', 'Telegram')
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

        $dernieresCommandes = \App\Models\Commande::where('entreprise_id', $entreprise->id)
            ->whereIn('client_id', $clientIds)
            ->whereIn('canal', ['Telegram', 'Messenger', 'Instagram', 'WhatsApp'])
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

    /**
     * GET /entreprises/{entreprise}/canaux/telegram/conversations/{client}/messages
     */
    public function messages(Entreprise $entreprise, Client $client)
    {
        $this->authorize('update', $entreprise);

        $messages = MessageCanal::where('entreprise_id', $entreprise->id)
            ->where('canal', 'Telegram')
            ->where('client_id', $client->id)
            ->orderBy('created_at')
            ->get(['id', 'direction', 'texte', 'media_url', 'media_type', 'source', 'created_at']);

        $commandes = \App\Models\Commande::where('entreprise_id', $entreprise->id)
            ->where('client_id', $client->id)
            ->where('canal', 'Telegram')
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

    /**
     * POST /entreprises/{entreprise}/canaux/telegram/envoyer
     * Body: { "client_id": 12, "texte": "..." }
     */
    public function envoyer(Request $request, Entreprise $entreprise)
    {
        $this->authorize('update', $entreprise);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'texte' => ['required', 'string'],
        ]);

        $canal = $entreprise->canaux()->where('type', 'telegram')->where('actif', true)->first();

        if (! $canal) {
            return response()->json(['message' => 'Telegram non connecté'], 422);
        }

        // Le chat_id est retrouvé via le dernier message entrant de ce client
        $dernierMessage = MessageCanal::where('entreprise_id', $entreprise->id)
            ->where('client_id', $data['client_id'])
            ->where('canal', 'Telegram')
            ->latest()
            ->first();

        if (! $dernierMessage) {
            return response()->json(['message' => 'Conversation introuvable'], 404);
        }

        $result = $this->telegram->envoyerMessage($canal, $dernierMessage->conversation_id, $data['texte']);

        if (! ($result['ok'] ?? false)) {
            return response()->json(['message' => "Échec de l'envoi Telegram"], 502);
        }

        $message = MessageCanal::create([
            'entreprise_id' => $entreprise->id,
            'client_id' => $data['client_id'],
            'canal' => 'Telegram',
            'direction' => 'sortant',
            'texte' => $data['texte'],
            'conversation_id' => $dernierMessage->conversation_id,
            'source' => 'manuel',
        ]);

        return response()->json($message, 201);
    }

    /**
     * GET /entreprises/{entreprise}/canaux/telegram/produits
     * Produits de l'entreprise pour le panneau latéral.
     */
    public function produits(Entreprise $entreprise)
    {
        $this->authorize('update', $entreprise);

        $produits = $entreprise->produits()
            ->select('id', 'nom', 'prix', 'stock')
            ->get();

        return response()->json($produits);
    }

    /**
     * POST /webhooks/telegram/{entreprise}/{secret}  — PUBLIC, appelé par Telegram
     */
    public function webhook(Request $request, int $entrepriseId, string $secret)
    {
        Log::debug('Webhook Telegram recu', ['entreprise_id' => $entrepriseId]);

        $canal = CanalEntreprise::where('entreprise_id', $entrepriseId)
            ->where('type', 'telegram')
            ->where('webhook_secret', $secret)
            ->first();

        $headerSecret = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (! $canal || ! $canal->actif || $headerSecret !== $secret) {
            Log::warning('Webhook Telegram rejeté', ['entreprise_id' => $entrepriseId]);
            return response()->json(['ok' => false], 403);
        }

        $message = $request->input('message');

        if (! $message) {
            return response()->json(['ok' => true]);
        }

        $chatId = (string) $message['chat']['id'];
        $from = $message['from'] ?? [];
        $nomClient = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''))
            ?: ($from['username'] ?? 'Client Telegram');

        $entreprise = Entreprise::find($entrepriseId);
        if (! $entreprise) {
            return response()->json(['ok' => false], 404);
        }

        $client = Client::firstOrCreate(
            ['identifiant_externe' => $chatId],
            ['nom' => $nomClient, 'canal_prefere' => 'Telegram']
        );

        $entreprise->clients()->syncWithoutDetaching([
            $client->id => [
                'plateforme_sociale' => 'telegram',
                'identifiant_social' => $chatId,
                'premier_contact_le' => now(),
            ],
        ]);

        // --- PHOTO (preuve de paiement) ---
        if (! empty($message['photo'])) {
            $this->traiterPhotoPreuve($canal, $entreprise, $client, $chatId, $message);
            return response()->json(['ok' => true]);
        }

        // --- TEXTE ---
        if (! isset($message['text'])) {
            return response()->json(['ok' => true]);
        }

        $texte = $message['text'];

        MessageCanal::create([
            'entreprise_id' => $entreprise->id,
            'client_id' => $client->id,
            'canal' => 'Telegram',
            'direction' => 'entrant',
            'texte' => $texte,
            'conversation_id' => $chatId,
            'source' => 'manuel',
        ]);

        try {
            $this->notifications->messageRecu($entreprise, $client, $texte, 'Telegram');
        } catch (\Throwable $e) {
            Log::warning('Notification message reçu échouée', ['entreprise_id' => $entrepriseId, 'erreur' => $e->getMessage()]);
        }

        try {
            $reponse = $this->reponses->repondre($entreprise, $client, $texte, 'Telegram');

            Log::debug('Reponse auto generee', ['texte' => mb_substr($reponse['message'] ?? '', 0, 100)]);

            $this->telegram->envoyerMessage($canal, $chatId, $reponse['message'], $reponse['lien_paiement'] ?? null);

            MessageCanal::create([
                'entreprise_id' => $entreprise->id,
                'client_id' => $client->id,
                'canal' => 'Telegram',
                'direction' => 'sortant',
                'texte' => $reponse['message'],
                'conversation_id' => $chatId,
                'source' => 'auto',
            ]);
        } catch (\Throwable $e) {
            Log::error('Réponse auto Telegram échouée', [
                'entreprise_id' => $entrepriseId,
                'chat_id' => $chatId,
                'erreur' => $e->getMessage(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Traite une photo reçue : OCR → recherche commande en_attente → enregistrement paiement auto.
     */
    private function traiterPhotoPreuve(
        CanalEntreprise $canal,
        Entreprise $entreprise,
        Client $client,
        string $chatId,
        array $message,
    ): void {
        // Telegram envoie plusieurs tailles, on prend la meilleure résolution (la dernière)
        $photos = $message['photo'];
        $bestPhoto = end($photos);
        $fileId = $bestPhoto['file_id'];

        // 1. Enregistrer le message photo
        $msgPhoto = MessageCanal::create([
            'entreprise_id' => $entreprise->id,
            'client_id' => $client->id,
            'canal' => 'Telegram',
            'direction' => 'entrant',
            'texte' => '[Photo — preuve de paiement]',
            'conversation_id' => $chatId,
            'source' => 'manuel',
        ]);

        // 2. Télécharger la photo
        $cheminPhoto = $this->telegram->telechargerPhoto($canal->token, $fileId);

        if (! $cheminPhoto) {
            $this->envoyerReponse($canal, $chatId, $entreprise, $client, 'Image non reçue. Veuillez réessayer ou envoyer une capture d\'écran plus nette.');
            return;
        }

        // Mettre à jour le message avec l'URL de la photo
        $mediaUrl = url('/storage/' . $cheminPhoto);
        $msgPhoto->update([
            'texte' => '[Photo — preuve de paiement]',
            'media_url' => $mediaUrl,
            'media_type' => 'image',
        ]);

        // 3. Trouver la dernière commande en_attente du client pour cette entreprise
        $derniereCommande = Commande::where('entreprise_id', $entreprise->id)
            ->where('client_id', $client->id)
            ->whereIn('statut', ['en_attente'])
            ->latest()
            ->first();

        if (! $derniereCommande) {
            $this->envoyerReponse($canal, $chatId, $entreprise, $client, 'Aucune commande en attente de paiement. Si vous souhaitez passer une commande, envoyez votre commande en texte.');
            return;
        }

        // 4. OCR — extraire le montant de la photo
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

        Log::info('OCR Photo preuve', [
            'commande_id' => $derniereCommande->id,
            'montant_attendu_acompte' => $montantAttendu,
            'montant_total' => $montantTotal,
            'texte_ocr' => $verification['texte'] ?? '',
            'montants_trouves' => $verification['montants_trouves'] ?? [],
            'match' => $verification['match'] ?? false,
            'montant_detecte' => $verification['montant_detecte'] ?? null,
            'chemin_photo' => $cheminPhoto,
        ]);

        // 5. Vérifier si le montant OCR correspond à l'acompte OU au total
        $montants = $verification['montants_trouves'] ?? [];
        $matchAcompte = false;
        $matchTotal = false;

        foreach ($montants as $m) {
            if (abs($m - $montantAttendu) < 1) $matchAcompte = true;
            if (abs($m - $montantTotal) < 1) $matchTotal = true;
        }

        if (! empty($verification['texte']) && ($matchAcompte || $matchTotal)) {
            // Montant détecté → enregistrer le paiement
            $montantAPayer = $matchTotal ? $montantTotal : $montantAttendu;

            $paiement = $this->paiements->enregistrerPaiement(
                $derniereCommande,
                $montantAPayer,
                'mobile_money',
                'Telegram — preuve photo',
                $uploadedFile,
                "telegram:{$chatId}:{$message['message_id']}",
            );

            $montantFmt = number_format($montantAPayer, 0, ',', ' ');
            $reste = max(0, $montantTotal - (float) $derniereCommande->fresh()->montant_paye);

            $msg = "Paiement de {$montantFmt} Ar enregistré pour la commande {$derniereCommande->numero}.";
            if ($reste > 0) {
                $resteFmt = number_format($reste, 0, ',', ' ');
                $msg .= " Il reste {$resteFmt} Ar à payer.";
            } else {
                $msg .= " Commande intégralement payée !";
            }

            $this->envoyerReponse($canal, $chatId, $entreprise, $client, $msg);
        } else {
            // Montant non reconnu ou ne correspond pas
            $detecteStr = ! empty($montants)
                ? 'Montant(s) détecté(s) : ' . implode(', ', array_map(fn ($m) => number_format($m, 0, ',', ' ') . ' Ar', $montants))
                : 'Aucun montant détecté sur l\'image.';

            $acompteFmt = number_format($montantAttendu, 0, ',', ' ');
            $totalFmt = number_format($montantTotal, 0, ',', ' ');

            $this->envoyerReponse(
                $canal,
                $chatId,
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
        string $chatId,
        Entreprise $entreprise,
        Client $client,
        string $texte,
    ): void {
        try {
            $this->telegram->envoyerMessage($canal, $chatId, $texte);

            MessageCanal::create([
                'entreprise_id' => $entreprise->id,
                'client_id' => $client->id,
                'canal' => 'Telegram',
                'direction' => 'sortant',
                'texte' => $texte,
                'conversation_id' => $chatId,
                'source' => 'auto',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Envoi Telegram echoue', ['chat_id' => $chatId, 'erreur' => $e->getMessage()]);
        }
    }
}
