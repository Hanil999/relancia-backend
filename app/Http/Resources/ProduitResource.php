<?php
// app/Http/Resources/ProduitResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProduitResource extends JsonResource
{
    public function toArray($request): array
    {
        $imageUrl = null;
        if ($this->image) {
            $imageUrl = filter_var($this->image, FILTER_VALIDATE_URL)
                ? $this->image // URL complète Cloudinary
                : $request->getSchemeAndHttpHost() . '/storage/' . $this->image;
        }

        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'image' => $this->image,
            'image_url' => $imageUrl,
            'description' => $this->description,
            'prix' => $this->prix,
            'stock' => $this->stock,
            'seuil_alerte_stock' => $this->seuil_alerte_stock,
            'sku' => $this->sku,
            'categorie_id' => $this->categorie_id,
            'categorie' => $this->categorie?->nom,
            'created_at' => $this->created_at,
        ];
    }
}
