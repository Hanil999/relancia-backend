<?php

namespace App\Http\Controllers;

use App\Models\Commande;
use App\Models\Entreprise;
use App\Models\Produit;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    private const LABELS_JOURS = ['dim', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam'];

    /**
     * Statistiques agrégées de l'entreprise pour le tableau de bord.
     * Toutes les valeurs sont calculées depuis les vraies données (commandes,
     * clients, produits) — rien de figé en dur côté frontend.
     */
    public function stats(Request $request, Entreprise $entreprise)
    {
        $this->authorize('voirCommandes', $entreprise);

        $aujourdhui = now()->startOfDay();
        $finDuJour = $aujourdhui->copy()->endOfDay();

        // --- Commandes ---
        $commandesDuJour = $entreprise->commandes()
            ->whereBetween('created_at', [$aujourdhui, $finDuJour])
            ->get(['statut', 'montant_total']);

        $totalCommandes = $entreprise->commandes()->count();
        $caTotal = $entreprise->commandes()
            ->where('statut', '!=', 'annulee')
            ->sum('montant_total');

        // --- Clients & produits ---
        $nbClients = $entreprise->clients()->count();

        $produits = Produit::query()
            ->where('entreprise_id', $entreprise->id)
            ->get(['stock', 'seuil_alerte_stock']);

        // --- Série des 7 derniers jours ---
        $commandes7Jours = $entreprise->commandes()
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->get(['statut', 'montant_total', 'created_at'])
            ->groupBy(fn (Commande $c) => $c->created_at->toDateString());

        $ventes7Jours = [];
        for ($i = 6; $i >= 0; $i--) {
            $jour = now()->subDays($i);
            $duJour = $commandes7Jours->get($jour->toDateString(), collect());
            $ventes7Jours[] = [
                'jour' => $jour->toDateString(),
                'label' => self::LABELS_JOURS[(int) $jour->dayOfWeek],
                'ventes' => (int) $duJour->where('statut', '!=', 'annulee')->sum('montant_total'),
                'commandes' => $duJour->count(),
            ];
        }

        // --- Répartition par canal ---
        $parCanal = $entreprise->commandes()->get(['canal'])->groupBy('canal');
        $totalCanaux = $parCanal->flatten()->count();
        $canaux = $parCanal->map(fn ($g, $canal) => [
            'canal' => $canal,
            'commandes' => $g->count(),
            'pourcentage' => $totalCanaux > 0 ? round($g->count() / $totalCanaux * 100) : 0,
        ])->values();

        // --- Répartition par statut ---
        $statuts = $entreprise->commandes()->get(['statut'])
            ->groupBy('statut')
            ->map(fn ($g, $statut) => [
                'statut' => $statut,
                'label' => Commande::LABELS_STATUT[$statut] ?? $statut,
                'commandes' => $g->count(),
            ])
            ->values();

        return response()->json([
            'ventes_du_jour' => (int) $commandesDuJour->where('statut', '!=', 'annulee')->sum('montant_total'),
            'commandes_du_jour' => $commandesDuJour->count(),
            'total_commandes' => $totalCommandes,
            'ca_total' => (int) $caTotal,
            'clients' => $nbClients,
            'produits_actifs' => $produits->count(),
            'rupture_stock' => $produits->where('stock', 0)->count(),
            'stock_faible' => $produits
                ->where('stock', '>', 0)
                ->filter(fn (Produit $p) => $p->stock <= $p->seuil_alerte_stock)
                ->count(),
            'ventes_7_jours' => $ventes7Jours,
            'canaux' => $canaux,
            'statuts' => $statuts,
        ]);
    }
}
