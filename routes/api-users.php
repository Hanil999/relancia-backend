<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\EmployeController;
use App\Http\Controllers\EntrepriseController;
use Illuminate\Support\Facades\Route;

// À inclure/fusionner dans routes/api.php, sous le middleware auth:sanctum (ou passport)

Route::middleware(['auth:sanctum'])->group(function () {

    // --- ADMIN : gestion globale des entreprises ---
    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/entreprises', [EntrepriseController::class, 'index']);
        Route::post('/admin/entreprises', [EntrepriseController::class, 'store']);
        Route::patch('/admin/entreprises/{entreprise}/toggle-actif', [EntrepriseController::class, 'toggleActive']);
    });

    // --- Commun ADMIN + GÉRANT (autorisation fine gérée par la Policy) ---
    Route::get('/entreprises/{entreprise}', [EntrepriseController::class, 'show']);
    Route::patch('/entreprises/{entreprise}', [EntrepriseController::class, 'update']);

    // --- GÉRANT : gestion des employés ---
    Route::middleware('role:gerant|admin')->group(function () {
        Route::get('/entreprises/{entreprise}/employes', [EmployeController::class, 'index']);
        Route::post('/entreprises/{entreprise}/employes', [EmployeController::class, 'store']);
        Route::patch('/entreprises/{entreprise}/employes/{employe}/toggle-actif', [EmployeController::class, 'toggleActive']);
        Route::delete('/entreprises/{entreprise}/employes/{employe}', [EmployeController::class, 'destroy']);
    });

    // --- GÉRANT + EMPLOYÉ : consultation des clients ---
    Route::middleware('role:gerant|employe|admin')->group(function () {
        Route::get('/entreprises/{entreprise}/clients', [ClientController::class, 'index']);
        Route::get('/entreprises/{entreprise}/clients/{clientId}', [ClientController::class, 'show']);
    });
});
