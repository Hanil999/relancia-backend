<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\CategorieController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\EmployeController;
use App\Http\Controllers\EntrepriseController;
use App\Http\Controllers\FacturationController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\InvitationGerantController;
use App\Http\Controllers\ProduitController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentification
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);

    Route::get('/{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->whereIn('provider', ['google', 'facebook']);
    Route::get('/{provider}/callback', [SocialAuthController::class, 'callback'])
        ->whereIn('provider', ['google', 'facebook']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

/*
|--------------------------------------------------------------------------
| Invitations EMPLOYÉ — publiques, l'employé n'a pas encore de compte
|--------------------------------------------------------------------------
*/
Route::prefix('invitations')->group(function () {
    Route::get('/{token}', [InvitationController::class, 'show']);
    Route::post('/{token}/accepter', [InvitationController::class, 'accept']);
    Route::post('/{token}/refuser', [InvitationController::class, 'decline']);
});

/*
|--------------------------------------------------------------------------
| Invitations GÉRANT — publiques, chemin distinct pour éviter toute collision
|--------------------------------------------------------------------------
*/
Route::prefix('invitations-gerant')->group(function () {
    Route::get('/{token}', [InvitationGerantController::class, 'show']);
    Route::post('/{token}/accepter', [InvitationGerantController::class, 'accept']);
    Route::post('/{token}/refuser', [InvitationGerantController::class, 'decline']);
});

/*
|--------------------------------------------------------------------------
| Routes authentifiées
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // --- Entreprises (admin) ---
    Route::middleware('role:admin')->group(function () {
        Route::get('/entreprises', [EntrepriseController::class, 'index']);
        Route::post('/entreprises', [EntrepriseController::class, 'store']);
        Route::get('/entreprises/archivees', [EntrepriseController::class, 'archives']);
        Route::post('/entreprises/{id}/restaurer', [EntrepriseController::class, 'restore']);
        Route::post('/entreprises/{entreprise}/suspendre', [EntrepriseController::class, 'suspend']);
        Route::delete('/entreprises/{entreprise}', [EntrepriseController::class, 'archive']);
        Route::delete('/invitations-gerant/{invitation}', [EntrepriseController::class, 'cancelInvitation']);
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
        Route::post('/{employe}/restaurer', [EmployeController::class, 'restore']);
    });

    Route::prefix('entreprises/{entreprise}/invitations')->group(function () {
        Route::delete('/{invitation}', [EmployeController::class, 'annulerInvitation']);
        Route::post('/{invitation}/renvoyer', [EmployeController::class, 'renvoyerInvitation']);
    });

    // --- Catalogue : produits & catégories (gérant + employés autorisés) ---
    Route::prefix('entreprises/{entreprise}')->group(function () {
        Route::get('produits', [ProduitController::class, 'index']);
        Route::post('produits', [ProduitController::class, 'store']);
        Route::get('produits/{produit}', [ProduitController::class, 'show']);
        Route::put('produits/{produit}', [ProduitController::class, 'update']);
        Route::delete('produits/{produit}', [ProduitController::class, 'destroy']);

        Route::get('categories', [CategorieController::class, 'index']);
        Route::post('categories', [CategorieController::class, 'store']);
        Route::put('categories/{categorie}', [CategorieController::class, 'update']);
        Route::delete('categories/{categorie}', [CategorieController::class, 'destroy']);
    });

    // --- Clients ---
    Route::get('/entreprises/{entreprise}/clients', [ClientController::class, 'index']);
    Route::get('/entreprises/{entreprise}/clients/{clientId}', [ClientController::class, 'show']);

    // --- Facturation ---
    Route::get('/facturation/plateforme', [FacturationController::class, 'plateforme'])
        ->middleware('role:admin');
    Route::get('/facturation/abonnement', [FacturationController::class, 'monAbonnement'])
        ->middleware('role:gerant');

    // --- Utilisateurs plateforme (vue globale admin) ---
    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/utilisateurs', function () {
            return response()->json(\App\Models\User::with('roles')->paginate(20));
        });
    });
});
