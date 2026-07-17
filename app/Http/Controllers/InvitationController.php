<?php

namespace App\Http\Controllers;

use App\Models\InvitationEmploye;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class InvitationController extends Controller
{
    /**
     * [PUBLIC] Détails d'une invitation à partir du token (pour affichage du formulaire).
     */
    public function show(string $token)
    {
        $invitation = InvitationEmploye::where('token', $token)->firstOrFail();

        abort_if(! $invitation->estValide(), 410, 'Cette invitation a expiré ou a déjà été utilisée.');

        return response()->json([
            'nom' => $invitation->nom,
            'email' => $invitation->email,
            'poste' => $invitation->poste,
            'entreprise' => $invitation->entreprise->only(['id', 'nom']),
        ]);
    }

    /**
     * [PUBLIC] Acceptation : l'employé choisit son mot de passe, le compte est créé.
     */
    public function accept(Request $request, string $token)
    {
        $invitation = InvitationEmploye::where('token', $token)->firstOrFail();

        abort_if(! $invitation->estValide(), 410, 'Cette invitation a expiré ou a déjà été utilisée.');

        $data = $request->validate([
            'nom' => ['sometimes', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $employe = User::create([
            'name' => $data['nom'] ?? $invitation->nom,
            'email' => $invitation->email,
            'password' => Hash::make($data['password']),
        ]);
        $employe->assignRole('employe');

        $invitation->entreprise->employes()->attach($employe->id, [
            'poste' => $invitation->poste,
            'actif' => true,
            'peut_gerer_catalogue' => $invitation->peut_gerer_catalogue,
            'invite_le' => $invitation->created_at,
        ]);

        $invitation->update(['acceptee_le' => now()]);

        $tokenAuth = $employe->createToken('api')->plainTextToken;

        return response()->json([
            'user' => $employe,
            'token' => $tokenAuth,
        ], 201);
    }
}
