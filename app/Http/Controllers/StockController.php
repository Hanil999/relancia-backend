<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApprovisionnerStockRequest;
use App\Http\Resources\ProduitResource;
use App\Models\Entreprise;
use App\Models\Produit;

class StockController extends Controller
{
    /**
     * Réapprovisionne un produit (augmentation du stock disponible).
     */
    public function approvisionner(ApprovisionnerStockRequest $request, Entreprise $entreprise, Produit $produit)
    {
        $this->authorize('gererCatalogue', $entreprise);
        abort_if($produit->entreprise_id !== $entreprise->id, 404);

        $produit->increment('stock', $request->validated()['quantite']);

        return new ProduitResource($produit->fresh()->load('categorie'));
    }
}
