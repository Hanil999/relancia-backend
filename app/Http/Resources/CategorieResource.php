<?php

// app/Http/Resources/CategorieResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CategorieResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'couleur' => $this->couleur,
            'produits' => $this->produits_count ?? $this->produits()->count(),
        ];
    }
}
