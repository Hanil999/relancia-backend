<?php

use App\Http\Controllers\StripeController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Page « Merci + facture » après paiement Stripe (PUBLIC, sans authentification)
Route::get('/paiements/stripe/succes', [StripeController::class, 'succes']);
Route::get('/paiements/stripe/succes/facture', [StripeController::class, 'facturePdf']);
