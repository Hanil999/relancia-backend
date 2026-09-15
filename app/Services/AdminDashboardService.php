<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Commande;
use App\Models\Entreprise;
use App\Models\Paiement;
use App\Models\Produit;

/**
 * Statistiques globales de la plateforme pour l'administrateur Relancia.
 *
 * Toutes les valeurs sont calculées depuis les vraies données (entreprises,
 * commandes, paiements, clients, produits) — aucun chiffre figé en dur.
 */
class AdminDashboardService
{
    /**
     * Vue d'ensemble de la plateforme : KPIs, évolution sur N jours,
     * top entreprises, répartition par canal et par statut, activité des paiements.
     */
    public function synthese(int $jours = 30): array
    {
        $debut = now()->subDays($jours - 1)->startOfDay();

        // --- Entreprises ---
        $entreprisesActives = Entreprise::where('actif', true)->count();
        $entreprisesTotal = Entreprise::count();
        $entreprisesNouvelles = Entreprise::where('created_at', '>=', $debut)->count();

        // --- Commandes & chiffre d'affaires (hors commandes annulées) ---
        $commandesNonAnnulees = Commande::where('statut', '!=', 'annulee');
        $caGlobal = (float) $commandesNonAnnulees->sum('montant_total');
        $nbCommandesNonAnnulees = $commandesNonAnnulees->count();
        $commandesTotal = Commande::count();
        $commandeMoyenne = $nbCommandesNonAnnulees > 0 ? round($caGlobal / $nbCommandesNonAnnulees, 2) : 0;

        // --- Aujourd'hui (volume + montant, hors annulées) ---
        $aujourdhui = now()->startOfDay();
        $commandesDuJour = Commande::whereBetween('created_at', [$aujourdhui, $aujourdhui->copy()->endOfDay()])
            ->get(['statut', 'montant_total']);

        // --- Clients & produits ---
        $clientsTotal = Client::count();
        $produitsTotal = Produit::count();

        // --- Derniers paiements encaissés ---
        $encaisseTotal = (float) Paiement::where('statut', 'paye')->sum('montant');

        // --- Évolution sur JOURS jours ---
        $commandesPeriode = Commande::where('created_at', '>=', $debut)
            ->orderBy('created_at')
            ->get(['statut', 'montant_total', 'created_at'])
            ->groupBy(fn (Commande $c) => $c->created_at->toDateString());

        $evolution = [];
        for ($i = $jours - 1; $i >= 0; $i--) {
            $jour = now()->subDays($i);
            $duJour = $commandesPeriode->get($jour->toDateString(), collect());
            $evolution[] = [
                'jour' => $jour->toDateString(),
                'label' => $jour->format('j/m'),
                'ventes' => (int) round($duJour->where('statut', '!=', 'annulee')->sum('montant_total')),
                'commandes' => $duJour->count(),
            ];
        }

        // --- Top 10 entreprises par chiffre d'affaires ---
        $topEntreprises = Commande::query()
            ->leftJoin('entreprises', 'entreprises.id', '=', 'commandes.entreprise_id')
            ->whereNull('entreprises.deleted_at')
            ->where('commandes.statut', '!=', 'annulee')
            ->selectRaw(
                'commandes.entreprise_id, entreprises.nom, entreprises.actif,
                 entreprises.commission_pct, COUNT(commandes.id) as nb_commandes,
                 SUM(commandes.montant_total) as ca_total'
            )
            ->groupBy('commandes.entreprise_id', 'entreprises.nom', 'entreprises.actif', 'entreprises.commission_pct')
            ->orderByDesc('ca_total')
            ->limit(10)
            ->get()
            ->map(fn ($e) => [
                'id' => (int) $e->entreprise_id,
                'nom' => $e->nom ?? '—',
                'actif' => (bool) $e->actif,
                'commission_pct' => (float) $e->commission_pct,
                'ca_total' => (int) round((float) $e->ca_total),
                'nb_commandes' => (int) $e->nb_commandes,
            ])
            ->values();

        // --- Répartition par canal de vente ---
        $parCanal = Commande::selectRaw('canal, COUNT(*) as total')->groupBy('canal')->get();
        $totalCanaux = $parCanal->sum('total');
        $repartitionCanaux = $parCanal->map(fn ($r) => [
            'canal' => $r->canal,
            'commandes' => (int) $r->total,
            'pourcentage' => $totalCanaux > 0 ? (int) round($r->total / $totalCanaux * 100) : 0,
        ])->values();

        // --- Répartition par statut de commande ---
        $repartitionStatuts = Commande::selectRaw('statut, COUNT(*) as total')
            ->groupBy('statut')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'statut' => $r->statut,
                'label' => Commande::LABELS_STATUT[$r->statut] ?? $r->statut,
                'commandes' => (int) $r->total,
            ])
            ->values();

        // --- Activité des paiements (total, succès, encaissé par méthode) ---
        $paiementsTotal = Paiement::count();
        $paiementsPayes = Paiement::where('statut', 'paye')->count();

        $parMethode = Paiement::selectRaw(
            "methode, COUNT(*) as total, SUM(CASE WHEN statut = 'paye' THEN montant ELSE 0 END) as encaisse"
        )->groupBy('methode')->get();

        $repartitionPaiements = $parMethode->map(fn ($r) => [
            'methode' => $r->methode,
            'total' => (int) $r->total,
            'encaisse' => (float) round((float) $r->encaisse, 2),
        ])->values();

        return [
            'plateforme' => [
                'entreprises_actives' => $entreprisesActives,
                'entreprises_total' => $entreprisesTotal,
                'entreprises_nouvelles' => $entreprisesNouvelles,
                'commandes_total' => $commandesTotal,
                'commandes_non_annulees' => $nbCommandesNonAnnulees,
                'ca_global' => $caGlobal,
                'commande_moyenne' => $commandeMoyenne,
                'clients_total' => $clientsTotal,
                'produits_total' => $produitsTotal,
                'ventes_aujourd_hui' => (int) round($commandesDuJour->where('statut', '!=', 'annulee')->sum('montant_total')),
                'commandes_aujourd_hui' => $commandesDuJour->count(),
                'encaisse_total' => $encaisseTotal,
            ],
            'paiements' => [
                'total' => $paiementsTotal,
                'payes' => $paiementsPayes,
                'taux_succes' => $paiementsTotal > 0 ? (int) round($paiementsPayes / $paiementsTotal * 100) : 0,
                'encaisse_total' => $encaisseTotal,
                'par_methode' => $repartitionPaiements,
            ],
            'evolution' => $evolution,
            'top_entreprises' => $topEntreprises,
            'repartition_canaux' => $repartitionCanaux,
            'repartition_statuts' => $repartitionStatuts,
        ];
    }
}