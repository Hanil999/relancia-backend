<?php
// À ajouter/fusionner dans routes/api.php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\SocialAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    // Authentification classique
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

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

// Exemple de route protégée par rôle (Spatie) — Sprint 1: gestion des rôles
Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/utilisateurs', function () {
        return response()->json(\App\Models\User::with('roles')->paginate(20));
    });
});
