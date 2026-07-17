<?php

namespace App\Http\Controllers;

use App\Models\Entreprise;
use App\Models\InvitationEmploye;
use App\Models\User;
use App\Notifications\InvitationEmployeNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class EmployeController extends Controller
{
    /**
     * [GÉRANT] Employés actifs + invitations en attente.
     */
    public function index(Entreprise $entreprise)
{
    $this->authorize('gererEmployes', $entreprise);

    $employes = $entreprise->employes()->get()->map(fn ($e) => [
        'id' => $e->id,
        'name' => $e->name,
        'email' => $e->email,
        'poste' => $e->pivot->poste,
        'actif' => (bool) $e->pivot->actif,
        'peut_gerer_catalogue' => (bool) $e->pivot->peut_gerer_catalogue,
    ]);

    $invitationsEnAttente = $entreprise->invitationsEmploye()
        ->whereNull('acceptee_le')
        ->where('expire_le', '>', now())
        ->get(['id', 'nom', 'email', 'poste', 'peut_gerer_catalogue', 'expire_le', 'created_at']);

    return response()->json([
        'employes' => $employes,
        'invitations_en_attente' => $invitationsEnAttente,
    ]);
}

    /**
     * [GÉRANT] Envoyer une invitation par email (plus de création directe de compte).
     */
    public function store(Request $request, Entreprise $entreprise)
{
    set_time_limit(60);

    $this->authorize('gererEmployes', $entreprise);

    $data = $request->validate([
        'nom' => ['required', 'string', 'max:255'],
        'email' => [
            'required', 'email',
            'unique:users,email',
            'unique:invitations_employe,email,NULL,id,entreprise_id,' . $entreprise->id . ',acceptee_le,NULL',
        ],
        'poste' => ['nullable', 'string', 'max:255'],
        'peut_gerer_catalogue' => ['sometimes', 'boolean'],
    ]);

    $invitation = InvitationEmploye::create([
        'entreprise_id' => $entreprise->id,
        'email' => $data['email'],
        'nom' => $data['nom'],
        'poste' => $data['poste'] ?? null,
        'peut_gerer_catalogue' => $data['peut_gerer_catalogue'] ?? false,
        'token' => InvitationEmploye::genererToken(),
        'invite_par_id' => $request->user()->id,
        'expire_le' => now()->addDays(7),
    ]);

    Notification::route('mail', $invitation->email)
        ->notify(new InvitationEmployeNotification($invitation));

    return response()->json($invitation, 201);
}

    /**
     * [GÉRANT] Annuler une invitation non acceptée.
     */
    public function annulerInvitation(Entreprise $entreprise, InvitationEmploye $invitation)
    {
        $this->authorize('gererEmployes', $entreprise);
        abort_if($invitation->entreprise_id !== $entreprise->id, 404);

        $invitation->delete();

        return response()->json(null, 204);
    }

    /**
     * [GÉRANT] Renvoyer l'email d'invitation (nouveau token, nouvelle expiration).
     */
    public function renvoyerInvitation(Entreprise $entreprise, InvitationEmploye $invitation)
    {
        $this->authorize('gererEmployes', $entreprise);
        abort_if($invitation->entreprise_id !== $entreprise->id, 404);
        abort_if($invitation->acceptee_le !== null, 422, 'Invitation déjà acceptée.');

        $invitation->update([
            'token' => InvitationEmploye::genererToken(),
            'expire_le' => now()->addDays(7),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new InvitationEmployeNotification($invitation));

        return response()->json($invitation);
    }

    /**
     * [GÉRANT] Activer / désactiver l'accès d'un employé.
     */
    public function toggleActive(Entreprise $entreprise, User $employe)
    {
        $this->authorize('gererEmployes', $entreprise);

        $pivot = $entreprise->employes()->where('users.id', $employe->id)->firstOrFail()->pivot;

        $entreprise->employes()->updateExistingPivot($employe->id, [
            'actif' => ! $pivot->actif,
        ]);

        return response()->json(['actif' => ! $pivot->actif]);
    }

    /**
     * [GÉRANT] Modifier la permission "gérer le catalogue" d'un employé.
     */
    public function togglePermissionCatalogue(Entreprise $entreprise, User $employe)
    {
        $this->authorize('gererEmployes', $entreprise);

        $pivot = $entreprise->employes()->where('users.id', $employe->id)->firstOrFail()->pivot;

        $entreprise->employes()->updateExistingPivot($employe->id, [
            'peut_gerer_catalogue' => ! $pivot->peut_gerer_catalogue,
        ]);

        return response()->json(['peut_gerer_catalogue' => ! $pivot->peut_gerer_catalogue]);
    }

    /**
     * [GÉRANT] Retirer un employé de l'entreprise.
     */
    public function destroy(Entreprise $entreprise, User $employe)
    {
        $this->authorize('gererEmployes', $entreprise);

        $entreprise->employes()->detach($employe->id);

        return response()->json(null, 204);
    }
}
