<?php

namespace App\Services;

use App\Mail\FactureMail;
use App\Models\Commande;
use App\Models\Facture;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class InvoiceService
{
    /**
     * Génère la facture PDF d'une commande confirmée et l'envoie par email
     * au client si une adresse est connue.
     */
    public function genererPourCommande(Commande $commande): Facture
    {
        abort_if($commande->facture, 422, 'Cette commande a déjà une facture.');

        $commande->loadMissing('items', 'client', 'entreprise');

        $numero = $this->genererNumero($commande->entreprise_id);

        $pdf = Pdf::loadView('pdf.facture', [
            'commande' => $commande,
            'numero' => $numero,
        ]);

        $cheminRelatif = "factures/{$commande->entreprise_id}/{$numero}.pdf";
        Storage::disk('public')->put($cheminRelatif, $pdf->output());

        $facture = Facture::create([
            'commande_id' => $commande->id,
            'entreprise_id' => $commande->entreprise_id,
            'numero' => $numero,
            'chemin_pdf' => $cheminRelatif,
        ]);

        if ($commande->client->email) {
            try {
                Mail::to($commande->client->email)->send(new FactureMail($facture));
                $facture->update(['envoyee_le' => now()]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $facture;
    }

    /**
     * Numérotation séquentielle par entreprise et par année. Ex: FAC-2026-000042
     */
    private function genererNumero(int $entrepriseId): string
    {
        $annee = now()->year;

        $dernier = Facture::where('entreprise_id', $entrepriseId)
            ->where('numero', 'like', "FAC-{$annee}-%")
            ->count();

        return sprintf('FAC-%d-%06d', $annee, $dernier + 1);
    }
}
