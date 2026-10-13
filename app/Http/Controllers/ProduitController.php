<?php
// app/Http/Controllers/ProduitController.php
namespace App\Http\Controllers;

use App\Http\Requests\StoreProduitRequest;
use App\Http\Requests\UpdateProduitRequest;
use App\Http\Resources\ProduitResource;
use App\Models\Entreprise;
use App\Models\Produit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProduitController extends Controller
{
    /** Supprime le fichier image local éventuel (les URLs Cloudinary sont ignorées). */
    private function deleteLocalImage(?string $image): void
    {
        if ($image && !filter_var($image, FILTER_VALIDATE_URL)) {
            Storage::disk('public')->delete($image);
        }
    }
    public function index(Request $request, Entreprise $entreprise)
    {
        $this->authorize('voirCatalogue', $entreprise);

        $produits = $entreprise->produits()
            ->when($request->search, fn ($q, $s) => $q->whereRaw('LOWER(nom) LIKE ?', ['%' . strtolower($s) . '%']))
            ->when($request->categorie_id, fn ($q, $c) => $q->where('categorie_id', $c))
            ->with('categorie')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ProduitResource::collection($produits);
    }

    public function store(StoreProduitRequest $request, Entreprise $entreprise)
    {
        $data = $request->validated();

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('produits', 'public');
        } elseif (is_string($request->input('image'))) {
            // URL Cloudinary fournie directement (upload effectué côté navigateur).
            $data['image'] = $request->input('image');
        }

        $produit = $entreprise->produits()->create($data);

        return new ProduitResource($produit->load('categorie'));
    }

    public function show(Entreprise $entreprise, Produit $produit)
    {
        $this->authorize('voirCatalogue', $entreprise);
        abort_if($produit->entreprise_id !== $entreprise->id, 404);

        return new ProduitResource($produit->load('categorie'));
    }

    public function update(UpdateProduitRequest $request, Entreprise $entreprise, Produit $produit)
    {
        abort_if($produit->entreprise_id !== $entreprise->id, 404);

        $data = $request->validated();
        unset($data['remove_image']); // pas une colonne, traité séparément ci-dessous

        if ($request->hasFile('image')) {
            $this->deleteLocalImage($produit->image);
            $data['image'] = $request->file('image')->store('produits', 'public');
        } elseif (is_string($request->input('image'))) {
            // URL Cloudinary fournie directement.
            $this->deleteLocalImage($produit->image);
            $data['image'] = $request->input('image');
        } elseif ($request->boolean('remove_image')) {
            $this->deleteLocalImage($produit->image);
            $data['image'] = null;
        }

        $produit->update($data);

        return new ProduitResource($produit->load('categorie'));
    }

    public function destroy(Entreprise $entreprise, Produit $produit)
    {
        $this->authorize('gererCatalogue', $entreprise);
        abort_if($produit->entreprise_id !== $entreprise->id, 404);

        $this->deleteLocalImage($produit->image);

        $produit->delete();

        return response()->json(null, 204);
    }
}
