<?php

namespace App\Http\Controllers;

use App\Models\CanalEntreprise;
use App\Models\Client;
use App\Models\Entreprise;
use App\Models\MessageCanal;
use App\Services\NotificationService;
use App\Services\SimulationService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TelegramController extends Controller
{
    public function __construct(
        private TelegramService $telegram,
        private SimulationService $simulation,
        private NotificationService $notifications,
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
            ->get(['id', 'direction', 'texte', 'source', 'created_at']);

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

        if (! $message || ! isset($message['text'])) {
            return response()->json(['ok' => true]);
        }

        $chatId = (string) $message['chat']['id'];
        $texte = $message['text'];
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
                'plateforme_sociale' => 'Telegram',
                'identifiant_social' => $chatId,
                'premier_contact_le' => now(),
            ],
        ]);

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
            $reponse = $this->simulation->repondre($entreprise, $client, $texte, 'Telegram');

            $this->telegram->envoyerMessage($canal, $chatId, $reponse['message']);

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
}
