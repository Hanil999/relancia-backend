<?php

// app/Http/Controllers/CategorieController.php
namespace App\Http\Controllers;

use App\Http\Requests\StoreCategorieRequest;
use App\Http\Requests\UpdateCategorieRequest;
use App\Http\Resources\CategorieResource;
use App\Models\Categorie;
use App\Models\Entreprise;

class CategorieController extends Controller
{
    public function index(Entreprise $entreprise)
    {
        $this->authorize('voirCatalogue', $entreprise);

        $categories = $entreprise->categories()
            ->withCount('produits')
            ->orderBy('nom')
            ->get();

        return CategorieResource::collection($categories);
    }

    public function store(StoreCategorieRequest $request, Entreprise $entreprise)
    {
        $categorie = $entreprise->categories()->create($request->validated());

        return new CategorieResource($categorie);
    }

    public function update(UpdateCategorieRequest $request, Entreprise $entreprise, Categorie $categorie)
    {
        abort_if($categorie->entreprise_id !== $entreprise->id, 404);

        $categorie->update($request->validated());

        return new CategorieResource($categorie);
    }

    public function destroy(Entreprise $entreprise, Categorie $categorie)
    {
        $this->authorize('gererCatalogue', $entreprise);
        abort_if($categorie->entreprise_id !== $entreprise->id, 404);

        $categorie->delete(); // produits liés passent categorie_id = null (nullOnDelete)

        return response()->json(null, 204);
    }
}
