<?php

namespace App\Http\Controllers;

use App\Services\AdminDashboardService;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    /**
     * Synthèse globale de la plateforme pour l'administrateur.
     * Données réelles, calculées à la volée (aucun cache).
     */
    public function synthese(Request $request, AdminDashboardService $service)
    {
        $jours = min(max((int) $request->query('jours', 30), 7), 90);

        return response()->json($service->synthese($jours));
    }
}