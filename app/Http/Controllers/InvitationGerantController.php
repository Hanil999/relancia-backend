<?php

namespace App\Http\Controllers;

use App\Models\Entreprise;
use App\Models\InvitationGerant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class InvitationGerantController extends Controller
{
    public function show(string $token)
    {
        $invitation = InvitationGerant::where('token', $token)->firstOrFail();

        abort_if(! $invitation->estValide(), 410, 'Cette invitation a expiré ou a déjà été utilisée.');

        return response()->json([
            'nom' => $invitation->nom,
            'email' => $invitation->email,
            'entreprise_nom' => $invitation->entreprise_nom,
            'entreprise_secteur_activite' => $invitation->entreprise_secteur_activite,
        ]);
    }

    public function decline(string $token)
    {
        $invitation = InvitationGerant::where('token', $token)->firstOrFail();

        abort_if(! $invitation->estValide(), 410, 'Cette invitation a expiré ou a déjà été utilisée.');

        $invitation->update(['refusee_le' => now()]);

        return response()->json(['message' => 'Invitation refusée.']);
    }

    /**
     * [PUBLIC] Le gérant choisit son mot de passe : création du User ET de l'Entreprise.
     */
    public function accept(Request $request, string $token)
    {
        $invitation = InvitationGerant::where('token', $token)->firstOrFail();

        abort_if(! $invitation->estValide(), 410, 'Cette invitation a expiré ou a déjà été utilisée.');

        $data = $request->validate([
            'nom' => ['sometimes', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        [$gerant, $entreprise, $tokenAuth] = \DB::transaction(function () use ($invitation, $data) {
            $gerant = User::create([
                'name' => $data['nom'] ?? $invitation->nom,
                'email' => $invitation->email,
                'password' => Hash::make($data['password']),
            ]);
            $gerant->assignRole('gerant');

            $entreprise = Entreprise::create([
                'gerant_id' => $gerant->id,
                'nom' => $invitation->entreprise_nom,
                'secteur_activite' => $invitation->entreprise_secteur_activite,
                'telephone' => $invitation->entreprise_telephone,
                'email_contact' => $invitation->entreprise_email_contact,
            ]);

            $invitation->update(['acceptee_le' => now()]);

            $tokenAuth = $gerant->createToken('api')->plainTextToken;

            return [$gerant, $entreprise, $tokenAuth];
        });

        return response()->json([
            'user' => $gerant,
            'entreprise' => $entreprise,
            'token' => $tokenAuth,
        ], 201);
    }
}
