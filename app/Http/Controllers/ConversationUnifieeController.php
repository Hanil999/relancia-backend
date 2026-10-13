<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Commande;
use App\Models\Entreprise;
use App\Models\MessageCanal;
use Illuminate\Http\Request;

/**
 * Fil de conversation unifié : mélange les messages de tous les canaux
 * (WhatsApp, Telegram, Messenger) pour un même client,
 * avec possibilité de filtrer par canal via le paramètre ?canal=.
 */
class ConversationUnifieeController extends Controller
{
    private const CANAUX = ['WhatsApp', 'Telegram', 'Messenger'];

    /** GET /entreprises/{entreprise}/conversations?canal= */
    public function conversations(Request $request, Entreprise $entreprise)
    {
        $this->authorize('update', $entreprise);

        $canal = $request->query('canal');
        if ($canal && ! in_array($canal, self::CANAUX, true)) {
            return response()->json(['message' => 'Canal invalide.'], 422);
        }

        $messages = MessageCanal::where('entreprise_id', $entreprise->id)
            ->with('client:id,nom')
            ->when($canal, fn ($q) => $q->where('canal', $canal))
            ->orderByDesc('created_at')
            ->get();

        $conversations = $messages
            ->groupBy('client_id')
            ->map(function ($liste) {
                $dernier = $liste->first();

                return [
                    'client_id' => $dernier->client_id,
                    'nom' => $dernier->client?->nom ?? 'Client #'.$dernier->client_id,
                    'canal' => $dernier->canal,
                    'canaux' => $liste->pluck('canal')->unique()->values()->all(),
                    'conversation_id' => $dernier->conversation_id,
                    'dernier_message' => $dernier->texte,
                    'direction' => $dernier->direction,
                    'date' => $dernier->created_at,
                ];
            })
            ->values();

        $clientIds = $conversations->pluck('client_id')->values()->all();

        $dernieresCommandes = Commande::where('entreprise_id', $entreprise->id)
            ->whereIn('client_id', $clientIds)
            ->latest()
            ->get()
            ->keyBy('client_id');

        $conversations = $conversations->map(function ($conv) use ($dernieresCommandes) {
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

        return response()->json($conversations);
    }

    /** GET /entreprises/{entreprise}/conversations/{client}/messages?canal= */
    public function messages(Request $request, Entreprise $entreprise, Client $client)
    {
        $this->authorize('update', $entreprise);

        $canal = $request->query('canal');
        if ($canal && ! in_array($canal, self::CANAUX, true)) {
            return response()->json(['message' => 'Canal invalide.'], 422);
        }

        $messages = MessageCanal::where('entreprise_id', $entreprise->id)
            ->where('client_id', $client->id)
            ->when($canal, fn ($q) => $q->where('canal', $canal))
            ->orderBy('created_at')
            ->get(['id', 'canal', 'direction', 'texte', 'media_url', 'media_type', 'source', 'statut_envoi', 'created_at']);

        $commandes = Commande::where('entreprise_id', $entreprise->id)
            ->where('client_id', $client->id)
            ->when($canal, fn ($q) => $q->where('canal', $canal))
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
}