<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommandeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'client' => [
                'id' => $this->client->id,
                'nom' => $this->client->nom,
                'telephone' => $this->client->telephone,
            ],
            'produits' => $this->items->map(fn ($item) => [
                'produit_id' => $item->produit_id,
                'nom' => $item->produit_nom,
                'quantite' => $item->quantite,
                'prix_unitaire' => $item->prix_unitaire,
                'sous_total' => $item->sous_total,
            ]),
            'produits_resume' => $this->items
                ->map(fn ($item) => "{$item->quantite}× {$item->produit_nom}")
                ->join(', '),
            'montant' => $this->montant_total,
            'canal' => $this->canal,
            'statut' => $this->statut,
            'statut_label' => $this->statut_label, // accesseur déjà défini sur le modèle Commande
            'date' => $this->created_at->format('d/m/Y'),
            'facture' => $this->facture ? [
                'id' => $this->facture->id,
                'numero' => $this->facture->numero,
                'telecharger' => "/api/auth/entreprises/{$this->entreprise_id}/factures/{$this->facture->id}/telecharger",
            ] : null,
        ];
    }
}
