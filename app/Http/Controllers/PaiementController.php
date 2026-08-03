<?php

namespace App\Http\Controllers;

use App\Models\Commande;
use App\Models\Entreprise;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class PaiementController extends Controller
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function store(Request $request, Entreprise $entreprise, Commande $commande)
    {
        $this->authorize('gererCommandes', $entreprise);
        abort_if($commande->entreprise_id !== $entreprise->id, 404);

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:0'],
            'methode' => ['required', 'in:especes,mobile_money,carte,virement'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $paiement = $commande->paiements()->create([
            ...$data,
            'statut' => 'paye',
            'paye_le' => now(),
        ]);

        $this->notifications->paiementRecu($paiement);

        return response()->json($paiement, 201);
    }
}
