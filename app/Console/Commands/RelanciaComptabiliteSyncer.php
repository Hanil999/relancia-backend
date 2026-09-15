<?php

namespace App\Console\Commands;

use App\Models\Commande;
use App\Models\Paiement;
use App\Services\RelanciaComptabiliteService;
use Illuminate\Console\Command;

class RelanciaComptabiliteSyncer extends Command
{
    protected $signature = 'relancia:comptabilite-syncer';

    protected $description = 'Alimente le grand livre Relancia (facturé/encaissé) depuis les données existantes';

    public function handle(RelanciaComptabiliteService $compta): int
    {
        $facturees = 0;
        $encaissees = 0;

        Commande::whereIn('statut', ['confirmee', 'expediee', 'livree'])
            ->get()
            ->each(function (Commande $commande) use ($compta, &$facturees) {
                if ($compta->facturerCommande($commande)) {
                    $facturees++;
                }
            });

        Paiement::where('statut', 'paye')
            ->with('commande.entreprise')
            ->get()
            ->each(function (Paiement $paiement) use ($compta, &$encaissees) {
                $entreprise = $paiement->commande?->entreprise;

                if ($entreprise) {
                    $commissionPct = $paiement->commission_pct !== null
                        ? (float) $paiement->commission_pct
                        : $entreprise->commissionPct();
                    $commission = $paiement->commission_relancia !== null
                        ? (float) $paiement->commission_relancia
                        : round((float) $paiement->montant * $commissionPct / 100, 2);

                    if ($paiement->commission_pct === null || $paiement->commission_relancia === null) {
                        $paiement->update([
                            'commission_pct' => $commissionPct,
                            'commission_relancia' => $commission,
                            'montant_reverse' => round((float) $paiement->montant - $commission, 2),
                        ]);
                    }
                }

                if ($compta->encaisser($paiement)) {
                    $encaissees++;
                }
            });

        $compte = $compta->compte();
        $this->info("Grand livre synchronisé : {$facturees} commissions facturées, {$encaissees} encaissées.");
        $this->info("Solde du compte Relancia : {$compte->solde} Ar.");

        return self::SUCCESS;
    }
}