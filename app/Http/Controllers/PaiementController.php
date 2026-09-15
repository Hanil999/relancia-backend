<?php

namespace App\Http\Controllers;

use App\Http\Resources\PaiementResource;
use App\Models\Commande;
use App\Models\Entreprise;
use App\Services\PaiementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaiementController extends Controller
{
    public function __construct(private readonly PaiementService $paiements)
    {
    }

    public function index(Request $request, Entreprise $entreprise, Commande $commande): JsonResponse
    {
        $this->authorize('gererCommandes', $entreprise);
        abort_if($commande->entreprise_id !== $entreprise->id, 404);

        $paiements = $commande->paiements()->orderByDesc('created_at')->get();

        return response()->json(PaiementResource::collection($paiements));
    }

    public function store(Request $request, Entreprise $entreprise, Commande $commande): JsonResponse
    {
        $this->authorize('gererCommandes', $entreprise);
        abort_if($commande->entreprise_id !== $entreprise->id, 404);

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:0.01'],
            'methode' => ['required', 'in:especes,mobile_money,carte,virement,stripe'],
            'reference' => ['nullable', 'string', 'max:255'],
            'preuve_image' => ['nullable', 'image', 'max:5120'],
            'idempotency_key' => ['nullable', 'string', 'max:191'],
        ]);

        $paiement = $this->paiements->enregistrerPaiement(
            $commande,
            $data['montant'],
            $data['methode'],
            $data['reference'] ?? null,
            $request->file('preuve_image'),
            $data['idempotency_key'] ?? null,
        );

        return response()->json(new PaiementResource($paiement), 201);
    }
}
