<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FactureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'commande_id' => $this->commande_id,
            'commande_numero' => $this->commande?->numero,
            'client' => $this->commande?->client?->nom,
            'montant' => (float) $this->commande?->montant_total,
            'date' => $this->created_at?->format('d/m/Y'),
            'statut' => $this->est_payee ? 'Payée' : 'En attente',
            'envoyee_le' => $this->envoyee_le?->format('d/m/Y H:i'),
            'telecharger' => "/api/auth/entreprises/{$this->entreprise_id}/factures/{$this->id}/telecharger",
        ];
    }
}
