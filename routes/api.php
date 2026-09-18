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
use App\Http\Controllers\CommandeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FactureController;
use App\Http\Controllers\MessageParserController;
use App\Http\Controllers\NotificationInterneController;
use App\Http\Controllers\PaiementController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\FacebookMessengerController;
use App\Http\Controllers\InstagramController;

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
        Route::apiResource('entreprises.commandes', CommandeController::class)
    ->only(['index', 'store', 'show', 'update', 'destroy']);

Route::post('entreprises/{entreprise}/commandes/{commandeId}/restaurer', [CommandeController::class, 'restore']);

Route::patch('entreprises/{entreprise}/commandes/{commande}/statut', [CommandeController::class, 'updateStatut']);

Route::post('entreprises/{entreprise}/messages/analyser', [MessageParserController::class, 'analyser']);

Route::apiResource('entreprises.clients', ClientController::class)
    ->only(['index', 'store', 'show']);

Route::get('entreprises/{entreprise}/factures', [FactureController::class, 'index']);

Route::get('entreprises/{entreprise}/factures/{facture}/telecharger', [FactureController::class, 'telecharger']);

Route::post('entreprises/{entreprise}/commandes/{commande}/facture', [FactureController::class, 'generer']);

Route::post('entreprises/{entreprise}/commandes/{commande}/paiements', [PaiementController::class, 'store']);
Route::get('entreprises/{entreprise}/commandes/{commande}/paiements', [PaiementController::class, 'index']);

Route::post('entreprises/{entreprise}/commandes/{commande}/stripe/session', [\App\Http\Controllers\StripeController::class, 'creerSession']);

Route::patch('entreprises/{entreprise}/settings', [EntrepriseController::class, 'updateSettings']);

Route::get('entreprises/{entreprise}/notifications', [NotificationInterneController::class, 'index']);
Route::patch('entreprises/{entreprise}/notifications/{notification}/lue', [NotificationInterneController::class, 'marquerLue']);
Route::post('entreprises/{entreprise}/notifications/tout-lire', [NotificationInterneController::class, 'marquerToutesLues']);

Route::get('entreprises/{entreprise}/stats', [DashboardController::class, 'stats']);

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

    // [GÉRANT] Onboarding : création de sa première entreprise (inscription
    // ou connexion réseau social) — hors du groupe ADMIN ci-dessus.
    Route::post('/entreprises/creer', [EntrepriseController::class, 'creerGerant']);

    Route::get('/entreprises/{entreprise}/canaux/telegram', [TelegramController::class, 'show']);
    Route::post('/entreprises/{entreprise}/canaux/telegram', [TelegramController::class, 'store']);
    Route::delete('/entreprises/{entreprise}/canaux/telegram', [TelegramController::class, 'destroy']);
    Route::post('/entreprises/{entreprise}/canaux/telegram/envoyer', [TelegramController::class, 'envoyer']);
    Route::get('/entreprises/{entreprise}/canaux/telegram/conversations', [TelegramController::class, 'conversations']);
    Route::get('/entreprises/{entreprise}/canaux/telegram/conversations/{client}/messages', [TelegramController::class, 'messages']);
    Route::get('/entreprises/{entreprise}/canaux/telegram/produits', [TelegramController::class, 'produits']);

    // === WHATSAPP ROUTES (authenticated) ===
    Route::get('/entreprises/{entreprise}/canaux/whatsapp', [WhatsAppController::class, 'show']);
    Route::post('/entreprises/{entreprise}/canaux/whatsapp', [WhatsAppController::class, 'store']);
    Route::delete('/entreprises/{entreprise}/canaux/whatsapp', [WhatsAppController::class, 'destroy']);
    Route::post('/entreprises/{entreprise}/canaux/whatsapp/envoyer', [WhatsAppController::class, 'envoyer']);
    Route::post('/entreprises/{entreprise}/canaux/whatsapp/abonner', [WhatsAppController::class, 'abonner']);
    Route::get('/entreprises/{entreprise}/canaux/whatsapp/conversations', [WhatsAppController::class, 'conversations']);
    Route::get('/entreprises/{entreprise}/canaux/whatsapp/conversations/{client}/messages', [WhatsAppController::class, 'messages']);
    Route::get('/entreprises/{entreprise}/canaux/whatsapp/produits', [WhatsAppController::class, 'produits']);

    // === FACEBOOK MESSENGER ROUTES (authenticated) ===
    Route::get('/entreprises/{entreprise}/canaux/messenger', [FacebookMessengerController::class, 'show']);
    Route::post('/entreprises/{entreprise}/canaux/messenger', [FacebookMessengerController::class, 'store']);
    Route::delete('/entreprises/{entreprise}/canaux/messenger', [FacebookMessengerController::class, 'destroy']);
    Route::post('/entreprises/{entreprise}/canaux/messenger/envoyer', [FacebookMessengerController::class, 'envoyer']);
    Route::post('/entreprises/{entreprise}/canaux/messenger/abonner', [FacebookMessengerController::class, 'abonner']);
    Route::get('/entreprises/{entreprise}/canaux/messenger/conversations', [FacebookMessengerController::class, 'conversations']);
    Route::get('/entreprises/{entreprise}/canaux/messenger/conversations/{client}/messages', [FacebookMessengerController::class, 'messages']);
    Route::get('/entreprises/{entreprise}/canaux/messenger/produits', [FacebookMessengerController::class, 'produits']);

    // === INSTAGRAM ROUTES (authenticated) ===
    Route::get('/entreprises/{entreprise}/canaux/instagram', [InstagramController::class, 'show']);
    Route::post('/entreprises/{entreprise}/canaux/instagram', [InstagramController::class, 'store']);
    Route::delete('/entreprises/{entreprise}/canaux/instagram', [InstagramController::class, 'destroy']);
    Route::post('/entreprises/{entreprise}/canaux/instagram/envoyer', [InstagramController::class, 'envoyer']);
    Route::post('/entreprises/{entreprise}/canaux/instagram/abonner', [InstagramController::class, 'abonner']);
    Route::get('/entreprises/{entreprise}/canaux/instagram/conversations', [InstagramController::class, 'conversations']);
    Route::get('/entreprises/{entreprise}/canaux/instagram/conversations/{client}/messages', [InstagramController::class, 'messages']);
    Route::get('/entreprises/{entreprise}/canaux/instagram/produits', [InstagramController::class, 'produits']);

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

        Route::post('produits/{produit}/approvisionner', [StockController::class, 'approvisionner']);

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
        Route::get('/admin/dashboard/synthese', [\App\Http\Controllers\AdminDashboardController::class, 'synthese']);
        Route::get('/admin/facturation/synthese', [\App\Http\Controllers\RelanciaComptabiliteController::class, 'synthese']);
        Route::patch('/admin/entreprises/{entreprise}/commission', [\App\Http\Controllers\RelanciaComptabiliteController::class, 'modifierCommission']);
    });
});

Route::post('/webhooks/telegram/{entreprise}/{secret}', [TelegramController::class, 'webhook']);

// === WHATSAPP WEBHOOKS (PUBLIC, no auth) ===
Route::get('/webhooks/whatsapp/{entreprise}/{secret}', [WhatsAppController::class, 'challenge']);
Route::post('/webhooks/whatsapp/{entreprise}/{secret}', [WhatsAppController::class, 'webhook']);

// === FACEBOOK MESSENGER WEBHOOKS (PUBLIC, no auth) ===
Route::get('/webhooks/messenger/{entreprise}/{secret}', [FacebookMessengerController::class, 'challenge']);
Route::post('/webhooks/messenger/{entreprise}/{secret}', [FacebookMessengerController::class, 'webhook']);

// === INSTAGRAM DM WEBHOOKS (PUBLIC, no auth) ===
Route::get('/webhooks/instagram/{entreprise}/{secret}', [InstagramController::class, 'challenge']);
Route::post('/webhooks/instagram/{entreprise}/{secret}', [InstagramController::class, 'webhook']);

// === STRIPE WEBHOOKS (PUBLIC, no auth) ===
Route::post('/webhooks/stripe', [\App\Http\Controllers\StripeController::class, 'webhook']);

// === STRIPE RETURN (PUBLIC) : après paiement, le client est ramené sur son bot Telegram ===
Route::get('/paiements/stripe/retour', [\App\Http\Controllers\StripeController::class, 'retour']);
