<?php

namespace App\Http\Controllers;

use App\Mail\InvitationGerantMail;
use App\Models\Entreprise;
use App\Models\InvitationGerant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EntrepriseController extends Controller
{
    /**
     * [ADMIN] Liste des entreprises actives/suspendues + invitations en attente.
     * Les entreprises archivées n'apparaissent pas ici (voir archives()).
     */
    public function index(Request $request)
    {
        $entreprises = Entreprise::query()
    ->withCount(['employes as employes_count' => function ($q) {
        $q->where('employe_entreprise.actif', true)
          ->whereNull('employe_entreprise.retire_le');
    }])
    ->latest()
    ->get()
    ->map(fn ($e) => [
        'id' => 'entreprise-' . $e->id,
        'nom' => $e->nom,
        'secteur' => $e->secteur_activite,
        'statut' => $e->statut,
        'employes' => $e->employes_count,
        'ventes' => $e->ventes_total ?? 0,
    ]);

        $invitationsEnAttente = InvitationGerant::query()
            ->whereNull('acceptee_le')
            ->whereNull('refusee_le')
            ->where('expires_at', '>', now())
            ->latest()
            ->get()
            ->map(fn ($inv) => [
                'id' => 'invitation-' . $inv->id,
                'nom' => $inv->entreprise_nom,
                'secteur' => $inv->entreprise_secteur_activite,
                'statut' => 'En attente',
                'employes' => 0,
                'ventes' => 0,
            ]);

        return response()->json(
            $entreprises->concat($invitationsEnAttente)->values()
        );
    }

    /**
     * [ADMIN] Invite un gérant : aucune entreprise n'est créée tant qu'il n'a pas accepté.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Entreprise::class);

        $data = $request->validate([
            'entreprise_nom' => ['required', 'string', 'max:255'],
            'entreprise_secteur_activite' => ['nullable', 'string', 'max:255'],
            'entreprise_telephone' => ['nullable', 'string', 'max:30'],
            'entreprise_email_contact' => ['nullable', 'email', 'max:255'],
            'gerant_nom' => ['required', 'string', 'max:255'],
            'gerant_email' => [
                'required', 'email',
                'unique:invitations_gerants,email',
                'unique:users,email',
            ],
        ]);

        $invitation = InvitationGerant::create([
            'token' => Str::random(64),
            'nom' => $data['gerant_nom'],
            'email' => $data['gerant_email'],
            'entreprise_nom' => $data['entreprise_nom'],
            'entreprise_secteur_activite' => $data['entreprise_secteur_activite'] ?? null,
            'entreprise_telephone' => $data['entreprise_telephone'] ?? null,
            'entreprise_email_contact' => $data['entreprise_email_contact'] ?? null,
            'invite_par' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($invitation->email)->send(new InvitationGerantMail($invitation));

        return response()->json([
            'id' => 'invitation-' . $invitation->id,
            'nom' => $invitation->entreprise_nom,
            'secteur' => $invitation->entreprise_secteur_activite,
            'statut' => 'En attente',
            'employes' => 0,
            'ventes' => 0,
        ], 201);
    }

    /**
     * [ADMIN] Annule une invitation encore en attente ("retirer" avant acceptation).
     */
    public function cancelInvitation(InvitationGerant $invitation)
    {
        $this->authorize('create', Entreprise::class); // même droit que l'invitation

        abort_if($invitation->acceptee_le, 422, 'Cette invitation a déjà été acceptée.');

        $invitation->delete();

        return response()->json(['message' => 'Invitation annulée.']);
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
     * [ADMIN] Suspendre / réactiver une entreprise (réversible, accès plateforme coupé).
     */
    public function suspend(Entreprise $entreprise)
    {
        $this->authorize('suspend', $entreprise);

        $entreprise->update(['actif' => ! $entreprise->actif]);

        return response()->json($entreprise);
    }

    /**
     * [ADMIN] Archiver (soft delete) une entreprise — retirée de la liste principale,
     * conservée dans l'archive, restaurable.
     */
    public function archive(Entreprise $entreprise)
    {
        $this->authorize('archive', $entreprise);

        $entreprise->delete();

        return response()->json(['message' => 'Entreprise archivée.']);
    }

    /**
     * [ADMIN] Liste des entreprises archivées.
     */
    public function archives()
    {
        $entreprises = Entreprise::onlyTrashed()
            ->latest('deleted_at')
            ->get()
            ->map(fn ($e) => [
                'id' => 'entreprise-' . $e->id,
                'nom' => $e->nom,
                'secteur' => $e->secteur_activite,
                'statut' => 'Archivé',
                'employes' => 0,
                'ventes' => $e->ventes_total ?? 0,
                'archivee_le' => $e->deleted_at,
            ]);

        return response()->json($entreprises);
    }

    /**
     * [ADMIN] Restaurer une entreprise archivée.
     */
    public function restore(int $id)
    {
        $entreprise = Entreprise::onlyTrashed()->findOrFail($id);

        $this->authorize('archive', $entreprise);

        $entreprise->restore();

        return response()->json($entreprise);
    }
}
