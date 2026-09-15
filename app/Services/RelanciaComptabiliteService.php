<?php

namespace App\Services;

use App\Models\Commande;
use App\Models\Entreprise;
use App\Models\Paiement;
use App\Models\RelanciaCompte;
use App\Models\RelanciaEcriture;
use Illuminate\Support\Facades\DB;

/**
 * Comptabilité de la plateforme Relancia.
 *
 * Relancia possède son propre compte : chaque commission est tracée dans un
 * grand livre (relancia_ecritures). Trois indicateurs clés :
 *   - Facturé   : part de Relancia sur les commandes confirmées (facturées)
 *   - Encaissé  : part effectivement reçue (paiements soldés)
 *   - En attente: différence = facturé - encaissé
 */
class RelanciaComptabiliteService
{
    public const TYP_FACTUREE = 'commission_facturee';
    public const TYP_ENCAISSEE = 'commission_encaissee';
    public const TYP_REMBOURSEMENT = 'remboursement';

    public function compte(): RelanciaCompte
    {
        return RelanciaCompte::first() ?? RelanciaCompte::create([
            'libelle' => 'Compte Relancia',
            'solde' => 0,
        ]);
    }

    /**
     * Commission Relancia (Ar) sur un montant donné selon le taux de l'entreprise.
     */
    public function montantCommission(float $montant, Entreprise $entreprise): float
    {
        return round($montant * $entreprise->commissionPct() / 100, 2);
    }

    /**
     * Enregistre la part de Relancia dès que la commande est confirmée / facturée.
     * Idempotent : une seule écriture "facturée" par commande.
     */
    public function facturerCommande(Commande $commande): ?RelanciaEcriture
    {
        $entreprise = $commande->entreprise;
        $montant = $this->montantCommission((float) $commande->montant_total, $entreprise);

        if ($montant <= 0 || $commande->statut === 'annulee') {
            return null;
        }

        $existant = RelanciaEcriture::where('commande_id', $commande->id)
            ->where('type', self::TYP_FACTUREE)
            ->exists();

        if ($existant) {
            return null;
        }

        return DB::transaction(function () use ($commande, $entreprise, $montant) {
            $compte = $this->compte();

            $ecriture = $compte->ecritures()->create([
                'entreprise_id' => $entreprise->id,
                'commande_id' => $commande->id,
                'type' => self::TYP_FACTUREE,
                'montant' => $montant,
                'commission_pct' => $entreprise->commissionPct(),
                'reference' => $commande->numero,
                'date_operation' => now(),
            ]);

            $this->recalculerSolde($compte);

            return $ecriture;
        });
    }

    /**
     * Enregistre la part réellement reçue quand un paiement est soldé.
     * Idempotent : une seule écriture "encaissée" par paiement.
     */
    public function encaisser(Paiement $paiement): ?RelanciaEcriture
    {
        $entrepriseId = $paiement->commande?->entreprise_id;
        $montant = (float) $paiement->commission_relancia;

        if ($montant <= 0 || $paiement->statut !== 'paye') {
            return null;
        }

        $existant = RelanciaEcriture::where('paiement_id', $paiement->id)
            ->where('type', self::TYP_ENCAISSEE)
            ->exists();

        if ($existant) {
            return null;
        }

        return DB::transaction(function () use ($paiement, $entrepriseId, $montant) {
            $compte = $this->compte();

            $ecriture = $compte->ecritures()->create([
                'entreprise_id' => $entrepriseId,
                'commande_id' => $paiement->commande_id,
                'paiement_id' => $paiement->id,
                'type' => self::TYP_ENCAISSEE,
                'montant' => $montant,
                'commission_pct' => $paiement->commission_pct,
                'reference' => $paiement->commande?->numero ?? $paiement->reference,
                'date_operation' => $paiement->paye_le ?? now(),
            ]);

            $this->recalculerSolde($compte);

            return $ecriture;
        });
    }

    /**
     * Retire l'écriture "encaissée" si un paiement n'est finalement plus payé
     * (ex: validation refusée, remboursement). Idempotent.
     */
    public function annulerEncaissement(Paiement $paiement): void
    {
        DB::transaction(function () use ($paiement) {
            RelanciaEcriture::where('paiement_id', $paiement->id)
                ->whereIn('type', [self::TYP_ENCAISSEE, self::TYP_REMBOURSEMENT])
                ->delete();

            $this->recalculerSolde($this->compte());
        });
    }

    /**
     * Consolide le solde du compte à partir du grand livre : en-caissée - remboursements.
     */
    public function recalculerSolde(?RelanciaCompte $compte = null): void
    {
        $compte ??= $this->compte();

        $encaisse = (float) RelanciaEcriture::where('type', self::TYP_ENCAISSEE)->sum('montant');
        $rembourse = (float) RelanciaEcriture::where('type', self::TYP_REMBOURSEMENT)->sum('montant');

        $compte->update(['solde' => round($encaisse - $rembourse, 2)]);
    }

    /**
     * Synthèse globale pour l'administrateur : compte Relancia + détail par entreprise.
     */
    public function synthese(): array
    {
        $compte = $this->compte();

        $facture = (float) RelanciaEcriture::where('type', self::TYP_FACTUREE)->sum('montant');
        $encaisse = (float) RelanciaEcriture::where('type', self::TYP_ENCAISSEE)->sum('montant');
        $rembourse = (float) RelanciaEcriture::where('type', self::TYP_REMBOURSEMENT)->sum('montant');
        $enAttente = $facture - $encaisse;

        $entreprises = Entreprise::withTrashed()
            ->whereHas('commandes')
            ->get()
            ->map(function (Entreprise $entreprise) {
                $facture = (float) RelanciaEcriture::where('entreprise_id', $entreprise->id)
                    ->where('type', self::TYP_FACTUREE)->sum('montant');
                $encaisse = (float) RelanciaEcriture::where('entreprise_id', $entreprise->id)
                    ->where('type', self::TYP_ENCAISSEE)->sum('montant');

                return [
                    'id' => $entreprise->id,
                    'nom' => $entreprise->nom,
                    'actif' => (bool) ($entreprise->actif ?? false),
                    'commission_pct' => (float) $entreprise->commissionPct(),
                    'ca_facture' => round($facture, 2),
                    'ca_encaisse' => round($encaisse, 2),
                    'ca_en_attente' => round(max(0, $facture - $encaisse), 2),
                ];
            })
            ->sortByDesc('ca_facture')
            ->values();

        return [
            'compte' => [
                'libelle' => $compte->libelle,
                'solde' => (float) $compte->solde,
                'total_facture' => round($facture, 2),
                'total_encaisse' => round($encaisse, 2),
                'total_rembourse' => round($rembourse, 2),
                'total_en_attente' => round(max(0, $enAttente), 2),
            ],
            'entreprises' => $entreprises,
        ];
    }

    /**
     * Dernières écritures du grand livre (journal), paginées.
     */
    public function ecritures(int $perPage = 25)
    {
        return RelanciaEcriture::with(['entreprise:id,nom', 'commande:id,numero', 'paiement:id,reference,methode'])
            ->orderByDesc('date_operation')
            ->paginate($perPage);
    }
}