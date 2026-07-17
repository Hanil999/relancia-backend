<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\EmployeController;
use App\Http\Controllers\EntrepriseController;
use App\Http\Controllers\FacturationController;
use App\Http\Controllers\InvitationController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    // Authentification classique
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    // Mot de passe oublié / réinitialisation
    Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);

    // OAuth Google / Facebook
    Route::get('/{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->whereIn('provider', ['google', 'facebook']);
    Route::get('/{provider}/callback', [SocialAuthController::class, 'callback'])
        ->whereIn('provider', ['google', 'facebook']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

// Invitations employé — publiques, pas d'auth (l'employé n'a pas encore de compte)
Route::prefix('invitations')->group(function () {
    Route::get('/{token}', [InvitationController::class, 'show']);
    Route::post('/{token}/accepter', [InvitationController::class, 'accept']);
});

Route::middleware('auth:sanctum')->group(function () {

    // --- Entreprises ---
    Route::middleware('role:admin')->group(function () {
        Route::get('/entreprises', [EntrepriseController::class, 'index']);
        Route::post('/entreprises', [EntrepriseController::class, 'store']);
        Route::patch('/entreprises/{entreprise}/toggle-actif', [EntrepriseController::class, 'toggleActive']);
    });

    // Accessible admin (concerné) + gérant propriétaire — filtré par la Policy
    Route::get('/entreprises/{entreprise}', [EntrepriseController::class, 'show']);
    Route::patch('/entreprises/{entreprise}', [EntrepriseController::class, 'update']);

    // --- Employés (gérant) ---
    Route::prefix('entreprises/{entreprise}/employes')->group(function () {
        Route::get('/', [EmployeController::class, 'index']);
        Route::post('/inviter', [EmployeController::class, 'store']);
        Route::patch('/{employe}/toggle-actif', [EmployeController::class, 'toggleActive']);
        Route::patch('/{employe}/toggle-catalogue', [EmployeController::class, 'togglePermissionCatalogue']);
        Route::delete('/{employe}', [EmployeController::class, 'destroy']);
    });

    Route::prefix('entreprises/{entreprise}/invitations')->group(function () {
        Route::delete('/{invitation}', [EmployeController::class, 'annulerInvitation']);
        Route::post('/{invitation}/renvoyer', [EmployeController::class, 'renvoyerInvitation']);
    });

    // --- Clients ---
    Route::get('/entreprises/{entreprise}/clients', [ClientController::class, 'index']);
    Route::get('/entreprises/{entreprise}/clients/{clientId}', [ClientController::class, 'show']);

    // --- Facturation --- (deux vues distinctes, cf. tableau)
    Route::get('/facturation/plateforme', [FacturationController::class, 'plateforme'])
        ->middleware('role:admin');
    Route::get('/facturation/abonnement', [FacturationController::class, 'monAbonnement'])
        ->middleware('role:gerant');

    // --- Utilisateurs plateforme (vue globale admin, distincte de "gérer mes employés") ---
    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/utilisateurs', function () {
            return response()->json(\App\Models\User::with('roles')->paginate(20));
        });
    });
});
