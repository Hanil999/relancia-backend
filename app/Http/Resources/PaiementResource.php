<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaiementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'montant' => (float) $this->montant,
            'methode' => $this->methode,
            'statut' => $this->statut,
            'reference' => $this->reference,
            'preuve_url' => $this->preuve_image ? url('/storage/' . $this->preuve_image) : null,
            'preuve_verifiee' => $this->preuve_verifiee,
            'montant_detecte' => $this->montant_detecte !== null ? (float) $this->montant_detecte : null,
            'verification_message' => $this->verification_message,
            'paye_le' => $this->paye_le?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
