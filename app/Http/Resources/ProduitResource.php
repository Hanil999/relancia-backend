<?php
// app/Http/Resources/ProduitResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProduitResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'image' => $this->image,
            'image_url' => $this->image ? Storage::disk('public')->url($this->image) : null,
            'description' => $this->description,
            'prix' => $this->prix,
            'stock' => $this->stock,
            'sku' => $this->sku,
            'categorie_id' => $this->categorie_id,
            'categorie' => $this->categorie?->nom,
            'created_at' => $this->created_at,
        ];
    }
}
