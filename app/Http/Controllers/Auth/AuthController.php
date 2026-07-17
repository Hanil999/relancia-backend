<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // Rôle par défaut pour tout nouvel utilisateur (Sprint 1 : gestion des rôles)
        $user->assignRole('user');

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
           'user' => [
    'id' => $user->id,
    'name' => $user->name,
    'email' => $user->email,
    'role' => $user->getRoleNames()->first(),
],            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (! $user || ! $user->password || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Identifiants invalides.'], 401);
        }

        // Durée du token plus longue si "se souvenir de moi" est coché (géré côté front via expires_at si besoin)
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
           'user' => [
    'id' => $user->id,
    'name' => $user->name,
    'email' => $user->email,
    'role' => $user->getRoleNames()->first(),
],            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté avec succès.']);
    }
public function me(Request $request)
{
    $user = $request->user();

    $peutGererCatalogue = false;

    if ($user->hasRole('gerant')) {
        $peutGererCatalogue = true;
    } elseif ($user->hasRole('employe')) {
        $pivotActif = $user->entreprisesEmploye()
            ->wherePivot('actif', true)
            ->first()
            ?->pivot;

        $peutGererCatalogue = (bool) ($pivotActif?->peut_gerer_catalogue ?? false);
    }

    return response()->json([
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getRoleNames()->first(),
            'peut_gerer_catalogue' => $peutGererCatalogue,
        ],
    ]);
}
}
