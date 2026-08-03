<?php

namespace App\Http\Controllers;

use App\Http\Requests\SimulationMessageRequest;
use App\Models\Client;
use App\Models\Entreprise;
use App\Services\NotificationService;
use App\Services\SimulationService;

/**
 * Simulation d'un client Messenger (Facebook non encore intégré) : le gérant
 * joue le rôle du client, le système répond sans IA (catalogue, prix, commandes).
 */
class SimulationController extends Controller
{
    public function __construct(
        private readonly SimulationService $simulation,
        private readonly NotificationService $notifications,
    ) {
    }

    public function repondre(SimulationMessageRequest $request, Entreprise $entreprise)
    {
        $this->authorize('gererCommandes', $entreprise);

        $data = $request->validated();

        $client = $this->resoudreClient($entreprise, $data);

        return response()->json(
            $this->simulation->repondre($entreprise, $client, $data['message'], $data['canal'])
        );
    }

    private function resoudreClient(Entreprise $entreprise, array $data): Client
    {
        if (! empty($data['client_id'])) {
            return $entreprise->clients()->findOrFail($data['client_id']);
        }

        $client = Client::create([
            'nom' => $data['client_nom'] ?? 'Client ' . ($data['canal'] ?? 'Messenger'),
        ]);

        $plateforme = match ($data['canal'] ?? 'Messenger') {
            'Messenger' => 'facebook',
            'Instagram' => 'instagram',
            'WhatsApp' => 'whatsapp',
            default => 'autre',
        };

        $entreprise->clients()->attach($client->id, [
            'plateforme_sociale' => $plateforme,
            'premier_contact_le' => now(),
        ]);

        $this->notifications->nouveauClient($entreprise, $client);

        return $client;
    }
}
