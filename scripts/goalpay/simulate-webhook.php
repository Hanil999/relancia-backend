<?php

/**
 * Simule un webhook payment.success de GoalPay vers le webhook Laravel local.
 *
 * Usage :
 *   php scripts/goalpay/simulate-webhook.php <commande_id> <montant> [order_reference]
 *
 * Prerequis :
 *   - serveur Laravel en ecoute (ex: php artisan serve --port=8001) ;
 *   - GOALPAY_WEBHOOK_SECRET renseigne dans le .env ;
 *   - une session GoalPay deja creee (POST .../goalpay/session) pour cette
 *     commande, sinon le webhook est ignore (paiement introuvable).
 */

use Illuminate\Support\Facades\Http;

require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$commandeId = (int) ($argv[1] ?? 0);
$montant = (float) ($argv[2] ?? 0);
$orderReference = $argv[3] ?? null;
$url = $argv[4] ?? 'http://127.0.0.1:8001/api/webhooks/goalpay';

if (! $commandeId || $montant <= 0) {
    echo "Usage : php simulate-webhook.php <commande_id> <montant> [order_reference]\n";
    exit(1);
}

$commande = App\Models\Commande::with('paiements')->find($commandeId);
if (! $commande) {
    echo "Commande introuvable.\n";
    exit(1);
}

if (! $orderReference) {
    $pending = $commande->paiements()->where('methode', 'goalpay')->where('statut', 'en_attente')->latest()->first();
    if (! $pending || ! $pending->goalpay_order_reference) {
        echo "Aucune session GoalPay en attente pour cette commande.\n";
        exit(1);
    }
    $orderReference = $pending->goalpay_order_reference;
}

echo "Commande    : #{$commande->id} {$commande->numero}\n";
echo "Order ref   : {$orderReference}\n";
echo "Montant     : {$montant} Ar\n";
echo "Webhook URL : {$url}\n\n";

$payload = json_encode([
    'event' => 'payment.success',
    'data' => [
        'order_reference' => $orderReference,
        'amount' => (int) $montant,
        'currency' => 'Ar',
        'description' => "Commande {$commande->numero}",
    ],
]);

$secret = config('services.goalpay.webhook_secret');
$signature = hash_hmac('sha256', $payload, (string) $secret);

$response = Http::withHeaders([
    'x-gpay-signature' => $signature,
    'Content-Type' => 'application/json',
])->post($url, json_decode($payload, true));

echo "HTTP " . $response->status() . " : " . $response->body() . "\n\n";

$commande->refresh();
$paiement = $commande->paiements()->where('methode', 'goalpay')->latest()->first();
if ($paiement) {
    echo "Paiement maintenant : {$paiement->statut} — {$paiement->montant} Ar";
    echo " (reverse {$paiement->montant_reverse})\n";
} else {
    echo "Aucun paiement goalpay.\n";
}