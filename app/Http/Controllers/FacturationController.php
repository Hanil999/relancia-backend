<?php

namespace App\Http\Controllers;

use App\Models\Entreprise;
use Illuminate\Http\Request;

class FacturationController extends Controller
{
    /**
     * [ADMIN] Vue globale plateforme : abonnements de toutes les entreprises.
     */
    public function plateforme(Request $request)
    {
        $entreprises = Entreprise::select('id', 'nom', 'actif', 'abonnement_expire_le')
            ->orderBy('abonnement_expire_le')
            ->paginate(20);

        return response()->json($entreprises);
    }

    /**
     * [GÉRANT] Son propre abonnement uniquement.
     */
    public function monAbonnement(Request $request)
    {
        $entreprise = $request->user()->entrepriseGeree;
        abort_if(! $entreprise, 404);

        return response()->json($entreprise->only(['id', 'nom', 'actif', 'abonnement_expire_le']));
    }
}
