<?php

namespace App\Http\Controllers;

use App\Models\Entreprise;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    /**
     * [GÉRANT + EMPLOYÉ] Liste des clients ayant contacté l'entreprise
     * via les réseaux sociaux.
     */
    public function index(Request $request, Entreprise $entreprise)
    {
        $this->authorize('voirClients', $entreprise);

        $clients = $entreprise->clients()
            ->when($request->query('plateforme'), function ($q, $plateforme) {
                $q->wherePivot('plateforme_sociale', $plateforme);
            })
            ->orderByPivot('premier_contact_le', 'desc')
            ->paginate(20);

        return response()->json($clients);
    }

    /**
     * [GÉRANT + EMPLOYÉ] Détail d'un client (historique, coordonnées).
     */
    public function show(Entreprise $entreprise, int $clientId)
    {
        $this->authorize('voirClients', $entreprise);

        $client = $entreprise->clients()->where('clients.id', $clientId)->firstOrFail();

        return response()->json($client);
    }
}
