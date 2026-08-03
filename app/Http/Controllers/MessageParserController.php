<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyserMessageRequest;
use App\Http\Resources\CommandeResource;
use App\Models\Client;
use App\Models\Entreprise;
use App\Services\CommandeService;
use App\Services\NotificationService;

/**
 * Point d'entrée de l'automatisation : reçoit le texte d'un message client
 * (Messenger/Instagram/WhatsApp) et tente d'en extraire une commande complète,
 * sans aucune saisie du commerçant.
 */
class MessageParserController extends Controller
{
    public function __construct(
        private readonly CommandeService $commandes,
        private readonly NotificationService $notifications,
    ) {
    }

    public function analyser(AnalyserMessageRequest $request, Entreprise $entreprise)
    {
        $this->authorize('gererCommandes', $entreprise);

        $data = $request->validated();

        $client = $this->resoudreClient($entreprise, $data);

        if ($client->wasRecentlyCreated) {
            $this->notifications->nouveauClient($entreprise, $client);
        }

        $resultat = $this->commandes->creerDepuisMessage(
            $entreprise,
            $client,
            $data['message'],
            $data['canal'],
        );

        return response()->json([
            'commande' => $resultat['commande'] ? new CommandeResource($resultat['commande']) : null,
            'non_reconnus' => $resultat['non_reconnus'],
            'ruptures' => $resultat['ruptures'],
        ]);
    }

    private function resoudreClient(Entreprise $entreprise, array $data): Client
    {
        if (! empty($data['client_id'])) {
            return $entreprise->clients()->findOrFail($data['client_id']);
        }

        $identifiant = $data['client_identifiant_externe'] ?? null;

        if ($identifiant !== null) {
            $client = $entreprise->clients()
                ->wherePivot('identifiant_social', $identifiant)
                ->first();

            if ($client) {
                return $client;
            }
        }

        return $entreprise->clients()->create([
            'nom' => $data['client_nom'] ?? 'Client ' . $data['canal'],
            'canal_prefere' => $data['canal'],
            'identifiant_externe' => $identifiant,
        ]);
    }
}
