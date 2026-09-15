<?php

namespace App\Services;

use App\Models\Commande;
use App\Models\Paiement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripeService
{
    private const PLACEHOLDER_SECRET = 'sk_live_xxxxx';

    /**
     * Le service Stripe n'est actif que si une vraie clé est configurée.
     */
    public function estConfigure(): bool
    {
        $secret = config('services.stripe.secret');

        return filled($secret) && $secret !== self::PLACEHOLDER_SECRET && $secret !== 'sk_test_xxxxx';
    }

    private function client(): StripeClient
    {
        return new StripeClient(config('services.stripe.secret'));
    }

    private function frontendUrl(string $path = ''): string
    {
        $base = rtrim(config('app.frontend_url', config('app.url')), '/');

        return $base . $path;
    }

    /**
     * URL publique du backend (app.url), par ex. pour le retour de paiement Stripe.
     */
    private function publicUrl(string $path = ''): string
    {
        return rtrim(config('app.url'), '/') . $path;
    }

    /**
     * Taux de conversion Ariary → devise Stripe (1 unité devise = X Ar).
     */
    public function tauxConversion(): float
    {
        return (float) config('services.stripe.taux_conversion', 4900);
    }

    /**
     * Créé une Checkout Session Stripe pour régler le montant demandé.
     *
     * @return array{url:string, session_id:string}
     *
     * @throws \RuntimeException si Stripe n'est pas configuré ou en cas d'erreur API
     */
    public function creerSessionCheckout(Commande $commande, float $montant): array
    {
        if (! $this->estConfigure()) {
            throw new \RuntimeException('Paiement par carte non disponible.');
        }

        // Conversion Ariary → devise Stripe (EUR) : le montant est stocké en Ar.
        $montantDevise = $montant / $this->tauxConversion();
        $montantCentimes = $this->montantEnCentimes($montantDevise);

        try {
            $params = [
                'mode' => 'payment',
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower(config('services.stripe.currency', 'EUR')),
                        'unit_amount' => $montantCentimes,
                        'product_data' => [
                            'name' => "Commande {$commande->numero}",
                            'description' => "Paiement via Relancia — {$commande->client?->nom}",
                        ],
                    ],
                    'quantity' => 1,
                ]],
                'metadata' => [
                    'commande_id' => (string) $commande->id,
                    'entreprise_id' => (string) $commande->entreprise_id,
                    'client_id' => (string) $commande->client_id,
                ],
                'success_url' => $this->publicUrl('/api/paiements/stripe/retour?session_id={CHECKOUT_SESSION_ID}'),
                'cancel_url' => $this->publicUrl('/api/paiements/stripe/retour?session_id={CHECKOUT_SESSION_ID}'),
            ];

            if (filled(optional($commande->client)->email)) {
                $params['customer_email'] = $commande->client->email;
            }

            $session = $this->client()->checkout->sessions->create($params);

            return [
                'url' => $session->url,
                'session_id' => $session->id,
            ];
        } catch (ApiErrorException $e) {
            Log::error('Stripe : création de session echouee', [
                'commande_id' => $commande->id,
                'message' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Erreur lors de la création du paiement par carte.');
        }
    }

    /**
     * Traite un webhook Stripe (événement checkout.session.completed).
     * Crée le paiement associé et la commission Relancia (5% par défaut).
     */
    public function traiterWebhook(string $payload, string $signature): array
    {
        $secret = config('services.stripe.webhook_secret');

        if (empty($secret) || $secret === 'whsec_xxxxx') {
            Log::warning('Stripe webhook accepté sans verif (secret absent)');
            $event = json_decode($payload, true);
        } else {
            try {
                $event = Webhook::constructEvent($payload, $signature, $secret);
            } catch (SignatureVerificationException $e) {
                Log::warning('Stripe webhook signature invalide', ['message' => $e->getMessage()]);
                abort(400, 'Signature invalide');
            }
        }

        if (($event['type'] ?? '') === 'checkout.session.completed') {
            $session = $event['data']['object'] ?? [];
            $this->enregistrerPaiementSession($session);
        }

        return ['ok' => true];
    }

    /**
     * Enregistre un paiement depuis une Checkout Session Stripe confirmée.
     */
    public function enregistrerPaiementSession(array $session): ?Paiement
    {
        $commandeId = $session['metadata']['commande_id'] ?? null;
        $sessionId = $session['id'] ?? null;

        if (! $commandeId || ! $sessionId) {
            Log::warning('Stripe : session sans commande_id ou sans id', ['session' => $session]);
            return null;
        }

        $commande = Commande::find((int) $commandeId);
        if (! $commande) {
            Log::warning('Stripe : commande introuvable', ['commande_id' => $commandeId]);
            return null;
        }

        // Éviter les doublons (Stripe peut renvoyer plusieurs fois le même événement)
        // et les doubles arrivées webhook + retour client en parallèle.
        $existant = $commande->paiements()->where('stripe_session_id', $sessionId)->first();
        if ($existant) {
            return $existant;
        }

        // amount_total est en centimes de la devise Stripe (EUR) → retour en Ariary.
        $montantAr = $this->centimesVersAriary((int) ($session['amount_total'] ?? 0));
        $commissionPct = $commande->entreprise->commissionPct();
        $commission = round($montantAr * $commissionPct / 100, 2);
        $montantReverse = $montantAr - $commission;

        try {
            $paiement = $commande->paiements()->create([
                'montant' => $montantAr,
                'methode' => 'stripe',
                'statut' => 'paye',
                'reference' => $sessionId,
                'idempotency_key' => "stripe:{$sessionId}",
                'stripe_session_id' => $sessionId,
                'stripe_payment_intent_id' => $session['payment_intent'] ?? null,
                'commission_pct' => $commissionPct,
                'commission_relancia' => $commission,
                'montant_reverse' => $montantReverse,
                'paye_le' => now(),
            ]);
        } catch (QueryException $e) {
            // La contrainte unique (stripe_session_id / idempotency_key) bloque la
            // concurrence webhook + retour : on renvoie le paiement déjà créé.
            if ($this->estViolationUnique($e)) {
                return $commande->paiements()->where('stripe_session_id', $sessionId)->first();
            }

            throw $e;
        }

        app(RelanciaComptabiliteService::class)->encaisser($paiement);

        app(NotificationService::class)->paiementRecu($paiement);
        $this->confirmerSiAcompteCouvert($commande);
        $commande->refresh();
        $this->notifierClient($commande, $paiement);

        Log::info('Stripe : paiement enregistre', [
            'commande_id' => $commande->id,
            'session_id' => $sessionId,
            'montant' => $montantAr,
            'commission_relancia' => $commission,
            'montant_reverse' => $montantReverse,
        ]);

        return $paiement;
    }

    /**
     * Si la commande était en attente et que le paiement couvre l'acompte requis,
     * on la confirme automatiquement (stock prélevé + facture + notif au client).
     */
    private function confirmerSiAcompteCouvert(Commande $commande): void
    {
        if ($commande->statut !== 'en_attente' || ! $commande->peutConfirmer) {
            return;
        }

        try {
            app(CommandeService::class)->changerStatut($commande, 'confirmee');
        } catch (\Throwable $e) {
            Log::warning('Confirmation auto apres paiement Stripe impossible', [
                'commande_id' => $commande->id,
                'erreur' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Convertit un montant (déjà exprimé dans la devise Stripe, ex: EUR) vers les centimes.
     */
    public function montantEnCentimes(float $montantDevise): int
    {
        $currency = strtolower(config('services.stripe.currency', 'EUR'));
        $zeroDecimal = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];

        if (in_array($currency, $zeroDecimal, true)) {
            return (int) round($montantDevise);
        }

        return (int) round($montantDevise * 100);
    }

    /**
     * Récupère une Checkout Session Stripe côté serveur (après le retour du client).
     */
    public function retrouverSession(string $sessionId): \Stripe\Checkout\Session
    {
        if (! $this->estConfigure()) {
            throw new \RuntimeException('Paiement par carte non disponible.');
        }

        return $this->client()->checkout->sessions->retrieve($sessionId);
    }

    /**
     * Convertit un montant en centimes (devise Stripe, ex: EUR centimes) vers l'Ariary.
     */
    public function centimesVersAriary(int $centimes): float
    {
        $montantDevise = $centimes / 100;

        return round($montantDevise * $this->tauxConversion(), 2);
    }

    private function notifierClient(Commande $commande, \App\Models\Paiement $paiement): void
    {
        $canal = $commande->entreprise->canalTelegram();

        if (! $canal || ! $canal->actif) {
            return;
        }

        $chatId = $commande->client?->entreprises()
            ->where('entreprises.id', $commande->entreprise_id)
            ->first()?->pivot?->identifiant_social
            ?? (string) $commande->client?->identifiant_externe;

        if (! $chatId) {
            $dernierMessage = \App\Models\MessageCanal::where('entreprise_id', $commande->entreprise_id)
                ->where('client_id', $commande->client_id)
                ->where('canal', 'Telegram')
                ->latest()
                ->first();
            $chatId = $dernierMessage?->conversation_id;
        }

        if (! $chatId) {
            return;
        }

        $montant = number_format((float) $paiement->montant, 0, ',', ' ');
        $reste = number_format((float) $commande->reste_a_payer, 0, ',', ' ');

        if (in_array($commande->statut, ['livree', 'expediee'], true)) {
            $texte = "Paiement de {$montant} Ar bien reçu pour la commande {$commande->numero}."
                . "\nMerci, votre commande est intégralement soldée !";
        } else {
            $texte = "Paiement reçu !\n"
                . "Votre commande {$commande->numero} sera livrée dans quelques heures.\n\n"
                . "Montant réglé : {$montant} Ar";

            if ((float) $commande->reste_a_payer > 0) {
                $texte .= "\nIl reste encore {$reste} Ar à régler.";
            }

            $texte .= "\nMerci pour votre confiance.";
        }

        try {
            app(TelegramService::class)->envoyerMessage($canal, $chatId, $texte);

            \App\Models\MessageCanal::create([
                'entreprise_id' => $commande->entreprise_id,
                'client_id' => $commande->client_id,
                'canal' => 'Telegram',
                'direction' => 'sortant',
                'texte' => $texte,
                'conversation_id' => $chatId,
                'source' => 'auto',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Notification paiement Stripe client echouee', [
                'commande_id' => $commande->id,
                'erreur' => $e->getMessage(),
            ]);
        }
    }

    private function estViolationUnique(QueryException $e): bool
    {
        return $e->getCode() === '23505'
            || str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains($e->getMessage(), 'duplicate key value violates unique constraint');
    }
}