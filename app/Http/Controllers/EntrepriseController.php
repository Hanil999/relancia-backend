<?php

namespace App\Http\Controllers;

use App\Models\Entreprise;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class EntrepriseController extends Controller
{
    /**
     * [ADMIN] Liste de toutes les entreprises de la plateforme.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Entreprise::class);

        $entreprises = Entreprise::with('gerant:id,name,email')
            ->when($request->query('recherche'), fn ($q, $recherche) => $q->where('nom', 'ilike', "%{$recherche}%"))
            ->paginate(15);

        return response()->json($entreprises);
    }

    /**
     * [ADMIN] Créer une entreprise + son compte gérant.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Entreprise::class);

        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'secteur_activite' => ['nullable', 'string', 'max:255'],
            'gerant_nom' => ['required', 'string', 'max:255'],
            'gerant_email' => ['required', 'email', 'unique:users,email'],
            'gerant_password' => ['required', 'string', 'min:8'],
        ]);

        $gerant = User::create([
            'name' => $data['gerant_nom'],
            'email' => $data['gerant_email'],
            'password' => Hash::make($data['gerant_password']),
        ]);
        $gerant->assignRole('gerant');

        $entreprise = Entreprise::create([
            'gerant_id' => $gerant->id,
            'nom' => $data['nom'],
            'secteur_activite' => $data['secteur_activite'] ?? null,
        ]);

        return response()->json($entreprise->load('gerant'), 201);
    }

    /**
     * [ADMIN + GÉRANT concerné] Détail d'une entreprise.
     */
    public function show(Entreprise $entreprise)
    {
        $this->authorize('view', $entreprise);

        return response()->json($entreprise->load(['gerant:id,name,email', 'employes:id,name,email']));
    }

    /**
     * [GÉRANT] Mise à jour des paramètres de sa propre entreprise.
     */
    public function update(Request $request, Entreprise $entreprise)
    {
        $this->authorize('update', $entreprise);

        $data = $request->validate([
            'nom' => ['sometimes', 'string', 'max:255'],
            'secteur_activite' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email_contact' => ['sometimes', 'nullable', 'email'],
            'telephone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        $entreprise->update($data);

        return response()->json($entreprise);
    }

    /**
     * [ADMIN] Suspendre / réactiver une entreprise (accès plateforme coupé).
     */
    public function toggleActive(Entreprise $entreprise)
    {
        $this->authorize('suspend', $entreprise);

        $entreprise->update(['actif' => ! $entreprise->actif]);

        return response()->json($entreprise);
    }
}
