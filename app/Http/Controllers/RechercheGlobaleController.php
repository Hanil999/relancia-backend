<?php

namespace App\Http\Controllers;

use App\Models\Entreprise;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recherche globale : clients, commandes, produits et catégories de l'entreprise.
 * Renvoie un nombre limité de résultats par groupe pour un menu de recherche rapide.
 */
class RechercheGlobaleController extends Controller
{
    private const MAX_PAR_GROUPE = 8;

    public function index(Request $request, Entreprise $entreprise): JsonResponse
    {
        $terme = trim((string) $request->query('q', ''));

        if ($terme === '') {
            return response()->json([
                'clients' => [],
                'commandes' => [],
                'produits' => [],
                'categories' => [],
            ]);
        }

        $this->authorize('voirCommandes', $entreprise);

        $clients = $entreprise->clients()
            ->where(function ($q) use ($terme) {
                $t = strtolower($terme);
                $q->whereRaw('LOWER(nom) LIKE ?', ["%{$t}%"])
                    ->orWhereRaw('LOWER(telephone) LIKE ?', ["%{$t}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$t}%"]);
            })
            ->withCount('commandes')
            ->orderBy('nom')
            ->limit(self::MAX_PAR_GROUPE)
            ->get();

        $commandes = $entreprise->commandes()
            ->where(function ($q) use ($terme) {
                $t = strtolower($terme);
                $q->whereRaw('LOWER(numero) LIKE ?', ["%{$t}%"])
                    ->orWhereHas('client', fn ($cq) => $cq->whereRaw('LOWER(nom) LIKE ?', ["%{$t}%"]));
            })
            ->with('client')
            ->latest()
            ->limit(self::MAX_PAR_GROUPE)
            ->get();

        $produits = $entreprise->produits()
            ->where(function ($q) use ($terme) {
                $t = strtolower($terme);
                $q->whereRaw('LOWER(nom) LIKE ?', ["%{$t}%"])
                    ->orWhereRaw('LOWER(sku) LIKE ?', ["%{$t}%"]);
            })
            ->with('categorie')
            ->orderBy('nom')
            ->limit(self::MAX_PAR_GROUPE)
            ->get();

        $categories = $entreprise->categories()
            ->whereRaw('LOWER(nom) LIKE ?', ['%' . strtolower($terme) . '%'])
            ->withCount('produits')
            ->orderBy('nom')
            ->limit(self::MAX_PAR_GROUPE)
            ->get();

        $imageUrl = static function (?string $image) use ($request): ?string {
            if (!$image) {
                return null;
            }

            return filter_var($image, FILTER_VALIDATE_URL)
                ? $image
                : $request->getSchemeAndHttpHost() . '/storage/' . $image;
        };

        return response()->json([
            'clients' => $clients->map(fn ($c) => [
                'id' => $c->id,
                'nom' => $c->nom,
                'telephone' => $c->telephone,
                'email' => $c->email,
                'canal_prefere' => $c->canal_prefere,
                'commandes_count' => $c->commandes_count ?? 0,
            ]),
            'commandes' => $commandes->map(fn ($cmd) => [
                'id' => $cmd->id,
                'numero' => $cmd->numero,
                'client' => $cmd->client ? [
                    'id' => $cmd->client->id,
                    'nom' => $cmd->client->nom,
                    'telephone' => $cmd->client->telephone,
                ] : null,
                'montant' => (float) $cmd->montant_total,
                'statut' => $cmd->statut,
                'statut_label' => $cmd->statut_label,
                'date' => $cmd->created_at?->format('d/m/Y'),
            ]),
            'produits' => $produits->map(fn ($p) => [
                'id' => $p->id,
                'nom' => $p->nom,
                'image_url' => $imageUrl($p->image),
                'prix' => (int) $p->prix,
                'stock' => (int) $p->stock,
                'categorie' => $p->categorie?->nom,
                'categorie_id' => $p->categorie_id,
            ]),
            'categories' => $categories->map(fn ($cat) => [
                'id' => $cat->id,
                'nom' => $cat->nom,
                'couleur' => $cat->couleur,
                'produits' => $cat->produits_count ?? 0,
            ]),
        ]);
    }
}