<?php

namespace App\Http\Controllers;

use App\Models\Entreprise;
use App\Services\RelanciaComptabiliteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RelanciaComptabiliteController extends Controller
{
    public function __construct(private readonly RelanciaComptabiliteService $compta)
    {
    }

    /**
     * [ADMIN] Compte Relancia + part de chaque entreprise (facturé / encaissé / en attente).
     */
    public function synthese(Request $request): JsonResponse
    {
        $synthese = $this->compta->synthese();
        $synthese['ecritures'] = $this->compta->ecritures((int) $request->integer('per_page', 15));

        return response()->json($synthese);
    }

    /**
     * [ADMIN] Ajuste le taux de commission d'une entreprise.
     */
    public function modifierCommission(Request $request, Entreprise $entreprise): JsonResponse
    {
        $data = $request->validate([
            'commission_pct' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $entreprise->update(['commission_pct' => $data['commission_pct']]);

        return response()->json([
            'message' => 'Commission mise à jour.',
            'entreprise' => [
                'id' => $entreprise->id,
                'nom' => $entreprise->nom,
                'commission_pct' => (float) $entreprise->commissionPct(),
            ],
        ]);
    }
}