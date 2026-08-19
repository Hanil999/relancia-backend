<?php

namespace App\Http\Controllers;

use App\Models\CanalEntreprise;
use App\Models\Client;
use App\Models\Entreprise;
use App\Models\MessageCanal;
use App\Services\NotificationService;
use App\Services\SimulationService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WhatsAppController extends Controller
{
    public function __construct(
        private WhatsAppService $whatsapp,
        private SimulationService $simulation,
        private NotificationService $notifications,
    ) {}

    /** GET /entreprises/{entreprise}/canaux/whatsapp */
    public function show(Entreprise $entreprise)
    {
        $this->authorize('update', $entreprise);

        $canal = $entreprise->canaux()->where('type', 'whatsapp')->first();

        if (! $canal) {
            return response()->json(['connecte' => false]);
        }

        return response()->json([
            'connecte' => $canal->actif,
            'bot_username' => $canal->bot_username,
            'phone_number_id' => $canal->bot_id,
            'connecte_le' => $canal->connecte_le,
        ]);
    }

    /** POST /entreprises/{entreprise}/canaux/whatsapp */
    public function store(Request $request, Entreprise $entreprise)
    {
        $this->authorize('update', $entreprise);

        $data = $request->validate([
            'phone_number_id' => ['required', 'string', 'min:5'],
            'access_token' => ['required', 'string', 'min:20'],
        ]);

        try {
            $canal = $this->whatsapp->connecter(
                $entreprise->id,
                $data['phone_number_id'],
                $data['access_token'],
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'connecte' => true,
            'bot_username' => $canal->bot_username,
        ], 201);
    }

    /** DELETE /entreprises/{entreprise}/canaux/whatsapp */
    public function destroy(Entreprise $entreprise)
    {
        $this->authorize('update', $entreprise);

        $canal = $entreprise->canaux()->where('type', 'whatsapp')->first();

        if ($canal) {
            $this->whatsapp->deconnecter($canal);
        }

        return response()->json(['connecte' => false]);
    }

    /** GET /entreprises/{entreprise}/canaux/whatsapp/conversations */
    public function conversations(Entreprise $entreprise)
    {
        $this->authorize('update', $entreprise);

        $derniers = MessageCanal::where('entreprise_id', $entreprise->id)
            ->where('canal', 'WhatsApp')
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
            ->whereIn('canal', ['WhatsApp', 'Telegram', 'Messenger', 'Instagram'])
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

    /** GET /entreprises/{entreprise}/canaux/whatsapp/conversations/{client}/messages */
    public function messages(Entreprise $entreprise, Client $client)
    {
        $this->authorize('update', $entreprise);

        $messages = MessageCanal::where('entreprise_id', $entreprise->id)
            ->where('canal', 'WhatsApp')
            ->where('client_id', $client->id)
            ->orderBy('created_at')
            ->get(['id', 'direction', 'texte', 'source', 'created_at']);

        $commandes = \App\Models\Commande::where('entreprise_id', $entreprise->id)
            ->where('client_id', $client->id)
            ->where('canal', 'WhatsApp')
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

    /** POST /entreprises/{entreprise}/canaux/whatsapp/envoyer */
    public function envoyer(Request $request, Entreprise $entreprise)
    {
        $this->authorize('update', $entreprise);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'texte' => ['required', 'string'],
        ]);

        $canal = $entreprise->canaux()->where('type', 'whatsapp')->where('actif', true)->first();

        if (! $canal) {
            return response()->json(['message' => 'WhatsApp non connecte'], 422);
        }

        $dernierMessage = MessageCanal::where('entreprise_id', $entreprise->id)
            ->where('client_id', $data['client_id'])
            ->where('canal', 'WhatsApp')
            ->latest()
            ->first();

        if (! $dernierMessage) {
            return response()->json(['message' => 'Conversation introuvable'], 404);
        }

        $result = $this->whatsapp->envoyerMessage($canal, $dernierMessage->conversation_id, $data['texte']);

        if (! ($result['ok'] ?? false)) {
            return response()->json(['message' => "Echec de l'envoi WhatsApp"], 502);
        }

        $message = MessageCanal::create([
            'entreprise_id' => $entreprise->id,
            'client_id' => $data['client_id'],
            'canal' => 'WhatsApp',
            'direction' => 'sortant',
            'texte' => $data['texte'],
            'conversation_id' => $dernierMessage->conversation_id,
            'source' => 'manuel',
        ]);

        return response()->json($message, 201);
    }

    /** GET /entreprises/{entreprise}/canaux/whatsapp/produits */
    public function produits(Entreprise $entreprise)
    {
        $this->authorize('update', $entreprise);

        $produits = $entreprise->produits()
            ->select('id', 'nom', 'prix', 'stock')
            ->get();

        return response()->json($produits);
    }

    /**
     * GET /webhooks/whatsapp/{entreprise}/{secret} — Meta webhook verification (challenge).
     */
    public function challenge(Request $request, int $entrepriseId, string $secret)
    {
        $canal = CanalEntreprise::where('entreprise_id', $entrepriseId)
            ->where('type', 'whatsapp')
            ->where('webhook_secret', $secret)
            ->first();

        if (! $canal) {
            return response('Forbidden', 403);
        }

        $mode = $request->query('hub.mode');
        $verifyToken = $request->query('hub.verify_token');
        $challenge = $request->query('hub.challenge');

        if ($mode === 'subscribe' && $verifyToken === $canal->webhook_secret) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    /**
     * POST /webhooks/whatsapp/{entreprise}/{secret} — PUBLIC, called by Meta on incoming messages.
     */
    public function webhook(Request $request, int $entrepriseId, string $secret)
    {
        $canal = CanalEntreprise::where('entreprise_id', $entrepriseId)
            ->where('type', 'whatsapp')
            ->where('webhook_secret', $secret)
            ->first();

        if (! $canal || ! $canal->actif) {
            Log::warning('Webhook WhatsApp rejete', ['entreprise_id' => $entrepriseId]);
            return response()->json(['ok' => false], 403);
        }

        // Verify HMAC signature if present
        $signature = $request->header('X-Hub-Signature-256');
        if ($signature) {
            $rawBody = $request->getContent();
            if (! $this->whatsapp->verifierSignature($canal, $rawBody, $signature)) {
                Log::warning('Signature WhatsApp invalide', ['entreprise_id' => $entrepriseId]);
                return response()->json(['ok' => false], 403);
            }
        }

        $payload = $request->json()->all();

        // Meta sends multiple entries; iterate to find messages
        $entries = $payload['entry'] ?? [];

        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];

            foreach ($changes as $change) {
                $value = $change['value'] ?? [];
                $messages = $value['messages'] ?? [];

                foreach ($messages as $msg) {
                    $this->traiterMessage($canal, $entrepriseId, $msg, $value);
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    private function traiterMessage(CanalEntreprise $canal, int $entrepriseId, array $msg, array $value): void
    {
        $phoneFrom = $msg['from'] ?? null;
        $texte = $msg['text']['body'] ?? null;
        $type = $msg['type'] ?? null;

        if (! $phoneFrom || $type !== 'text' || ! $texte) {
            return;
        }

        $entreprise = Entreprise::find($entrepriseId);
        if (! $entreprise) {
            return;
        }

        // Extract display name from contacts array if available
        $contacts = $value['contacts'] ?? [];
        $nomClient = 'Client WhatsApp';
        if (! empty($contacts[0]['profile']['name'])) {
            $nomClient = $contacts[0]['profile']['name'];
        }

        $client = Client::firstOrCreate(
            ['identifiant_externe' => $phoneFrom],
            ['nom' => $nomClient, 'canal_prefere' => 'WhatsApp']
        );

        $entreprise->clients()->syncWithoutDetaching([
            $client->id => [
                'plateforme_sociale' => 'WhatsApp',
                'identifiant_social' => $phoneFrom,
                'premier_contact_le' => now(),
            ],
        ]);

        MessageCanal::create([
            'entreprise_id' => $entreprise->id,
            'client_id' => $client->id,
            'canal' => 'WhatsApp',
            'direction' => 'entrant',
            'texte' => $texte,
            'conversation_id' => $phoneFrom,
            'source' => 'manuel',
        ]);

        try {
            $this->notifications->messageRecu($entreprise, $client, $texte, 'WhatsApp');
        } catch (\Throwable $e) {
            Log::warning('Notification message WhatsApp recu echouee', [
                'entreprise_id' => $entrepriseId,
                'erreur' => $e->getMessage(),
            ]);
        }

        try {
            $reponse = $this->simulation->repondre($entreprise, $client, $texte, 'WhatsApp');

            $this->whatsapp->envoyerMessage($canal, $phoneFrom, $reponse['message']);

            MessageCanal::create([
                'entreprise_id' => $entreprise->id,
                'client_id' => $client->id,
                'canal' => 'WhatsApp',
                'direction' => 'sortant',
                'texte' => $reponse['message'],
                'conversation_id' => $phoneFrom,
                'source' => 'auto',
            ]);
        } catch (\Throwable $e) {
            Log::error('Reponse auto WhatsApp echouee', [
                'entreprise_id' => $entrepriseId,
                'phone' => $phoneFrom,
                'erreur' => $e->getMessage(),
            ]);
        }
    }
}
