<?php

namespace App\Services;

use App\Models\Commande;
use App\Models\MessageCanal;
use App\Models\Paiement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoalpayService
{
    private const PLACEHOLDER_TOKEN = 'TGP_xxxxx';

    public function estConfigure(): bool
    {
        $token = config('services.goalpay.token');

        return filled($token)
            && $token !== self::PLACEHOLDER_TOKEN
            && $token !== 'TGP_';
    }

    private function apiUrl(string $path = ''): string
    {
        return rtrim(config('services.goalpay.api_url', 'https://api.goalpay.pro'), '/').$path;
    }

    /**
     * Endpoint de création de paiement : sandbox pour les comptes de test,
     * production pour les comptes réels.
     */
    private function pathPaiement(): string
    {
        return config('services.goalpay.sandbox', false)
            ? '/api/sandbox/payement/service'
            : '/api/payement/service';
    }

    /**
     * Créé une session de paiement GoalPay (Mobile Money — Ariary).
     *
     * @return array{url:string, session_id:string}
     *
     * @throws \RuntimeException si GoalPay n'est pas configuré ou en cas d'erreur API
     */
    public function creerSessionCheckout(Commande $commande, float $montant): array
    {
        if (! $this->estConfigure()) {
            throw new \RuntimeException('Paiement GoalPay non disponible.');
        }

        $montantAr = (int) round($montant);

        $payload = [
            'description' => "Commande {$commande->numero} — Relancia",
            'access' => config('services.goalpay.token'),
            'reference' => $this->genererReference($commande),
            'amount' => $montantAr,
            'currency' => 'Ar',
            'metadata' => $this->metadonneesLignes($commande, $montantAr),
        ];

        try {
            $response = Http::timeout(60)
                ->acceptJson()
                ->post($this->apiUrl($this->pathPaiement()), $payload);
        } catch (\Throwable $e) {
            Log::error('GoalPay : appel API echoue', [
                'commande_id' => $commande->id,
                'message' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Erreur lors de la création du paiement GoalPay.');
        }

        if (! $response->successful()) {
            Log::error('GoalPay : reponse API en erreur', [
                'commande_id' => $commande->id,
                'status' => $response->status(),
                'corps' => $response->body(),
            ]);

            throw new \RuntimeException('Erreur lors de la création du paiement GoalPay.');
        }

        $data = $response->json('data') ?? [];
        $orderReference = $data['order_reference'] ?? null;
        $checkoutUrl = $data['checkout_url'] ?? null;

        if (! $orderReference || ! $checkoutUrl) {
            Log::error('GoalPay : reponse sans order_reference/checkout_url', [
                'commande_id' => $commande->id,
                'corps' => $response->body(),
            ]);

            throw new \RuntimeException('Erreur lors de la création du paiement GoalPay.');
        }

        $this->creerPaiementEnAttente($commande, $montant, $orderReference, $checkoutUrl);

        return [
            'url' => $checkoutUrl,
            'session_id' => $orderReference,
        ];
    }

    /**
     * Enregistre le paiement en attente dès la création de la session, afin de
     * retrouver la commande au moment du webhook (GoalPay ne renvoie que des
     * références, pas de metadata commande).
     */
    private function creerPaiementEnAttente(Commande $commande, float $montant, string $orderReference, string $checkoutUrl): Paiement
    {
        return $commande->paiements()->create([
            'montant' => $montant,
            'methode' => 'goalpay',
            'statut' => 'en_attente',
            'reference' => $orderReference,
            'idempotency_key' => "goalpay:{$orderReference}",
            'goalpay_order_reference' => $orderReference,
            'paye_le' => null,
        ]);
    }

    /**
     * Traite un webhook GoalPay.
     * Events : payment.success / payment.failed / payment.canceled / payment.expired.
     */
    public function traiterWebhook(string $rawBody, string $signature): array
    {
        if (! $this->verifierSignature($rawBody, $signature)) {
            Log::warning('GoalPay : signature webhook invalide');
            abort(400, 'Signature invalide');
        }

        $payload = json_decode($rawBody, true);
        $event = $payload['event'] ?? $payload['type'] ?? '';
        $data = $payload['data'] ?? $payload;

        if ($event === 'payment.success') {
            $this->enregistrerPaiementWebhook($data);
        } else {
            Log::info('GoalPay : evenement ignore', ['event' => $event, 'reference' => $data['order_reference'] ?? null]);
        }

        return ['ok' => true];
    }

    /**
     * Marque le paiement comme payé après confirmation GoalPay et enchaîne
     * l'encaissement de la commission Relancia + confirmation client.
     */
    public function enregistrerPaiementWebhook(array $data): ?Paiement
    {
        $orderReference = $data['order_reference'] ?? null;

        if (! $orderReference) {
            Log::warning('GoalPay : webhook sans order_reference', ['data' => $data]);

            return null;
        }

        $paiement = Paiement::where('goalpay_order_reference', $orderReference)->first();

        if (! $paiement) {
            Log::warning('GoalPay : paiement introuvable', ['order_reference' => $orderReference]);

            return null;
        }

        if ($paiement->statut === 'paye') {
            return $paiement;
        }

        $montantWebhook = (int) ($data['amount'] ?? 0);
        $montant = $montantWebhook > 0
            ? (float) $montantWebhook
            : (float) $paiement->montant;

        $commande = $paiement->commande;
        $commissionPct = $commande->entreprise->commissionPct();
        $commission = round($montant * $commissionPct / 100, 2);
        $montantReverse = $montant - $commission;

        try {
            $paiement->update([
                'montant' => $montant,
                'statut' => 'paye',
                'commission_pct' => $commissionPct,
                'commission_relancia' => $commission,
                'montant_reverse' => $montantReverse,
                'paye_le' => now(),
            ]);
        } catch (QueryException $e) {
            Log::error('GoalPay : mise a jour paiement echouee', [
                'paiement_id' => $paiement->id,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }

        app(RelanciaComptabiliteService::class)->encaisser($paiement);

        app(NotificationService::class)->paiementRecu($paiement);
        $this->confirmerSiAcompteCouvert($commande);
        $commande->refresh();
        $this->notifierClient($commande, $paiement);
        $paiement->refresh();

        Log::info('GoalPay : paiement enregistre', [
            'commande_id' => $commande->id,
            'order_reference' => $orderReference,
            'montant' => $montant,
            'commission_relancia' => $commission,
            'montant_reverse' => $montantReverse,
        ]);

        return $paiement;
    }

    /**
     * Vérifie la signature HMAC-SHA256 du webhook.
     * Le secret non configuré (ou le flag de vérification désactivé) autorise
     * la réception en mode développement.
     */
    public function verifierSignature(string $rawBody, string $signature): bool
    {
        $secret = config('services.goalpay.webhook_secret');

        if (! config('services.goalpay.verify_signature', true)) {
            return true;
        }

        if (empty($secret)) {
            Log::warning('GoalPay : secret webhook absent, accepte sans verif');

            return true;
        }

        if (empty($signature)) {
            return false;
        }

        // HMAC sur le corps de la requête (convention standard), et HMAC sur le
        // secret seul (exemple officiel de la doc GoalPay) — on accepte les deux.
        $attendu = hash_hmac('sha256', $rawBody, $secret);
        $attenduSecretSeul = hash_hmac('sha256', $secret, $secret);

        return hash_equals($attendu, $signature)
            || hash_equals($attenduSecretSeul, $signature);
    }

    /**
     * Référence unique côté marchand (le numéro de commande peut se répéter en
     * cas de paiement partiel, on ajoute donc un suffixe).
     */
    private function genererReference(Commande $commande): string
    {
        return $commande->numero.'-'.strtoupper(substr(uniqid(), -6));
    }

    /**
     * Construit le tableau metadata attendu par GoalPay : [{ label, unit_price, quantity }].
     *
     * GoalPay refuse toute session dont la somme des lignes differe du montant
     * demande. Or le montant peut etre un acompte (partiel) : on rescale donc
     * les lignes sur le montant reellement facture et, si le prorata tombe mal,
     * on retombe sur une ligne unique au montant exact.
     */
    private function metadonneesLignes(Commande $commande, int $montantAr): array
    {
        $lignes = [];

        foreach ($commande->items as $item) {
            if (! $item->produit) {
                continue;
            }

            $lignes[] = [
                'label' => $item->produit->nom,
                'unit_price' => (int) round((float) $item->prix_unitaire),
                'quantity' => (int) $item->quantite,
            ];
        }

        $totalLignes = array_sum(array_map(
            fn (array $l) => $l['unit_price'] * $l['quantity'],
            $lignes,
        ));

        if ($lignes === [] || $totalLignes === $montantAr) {
            return $lignes ?: [[
                'label' => "Commande {$commande->numero}",
                'unit_price' => $montantAr,
                'quantity' => 1,
            ]];
        }

        // Paiement partiel (acompte) : une seule ligne au montant exact.
        $libelle = $montantAr < $totalLignes
            ? "Acompte — commande {$commande->numero}"
            : "Commande {$commande->numero}";

        return [[
            'label' => $libelle,
            'unit_price' => $montantAr,
            'quantity' => 1,
        ]];
    }

    /**
     * Si la commande était en attente et que le paiement couvre l'acompte requis,
     * on la confirme automatiquement.
     */
    private function confirmerSiAcompteCouvert(Commande $commande): void
    {
        if ($commande->statut !== 'en_attente' || ! $commande->peutConfirmer) {
            return;
        }

        try {
            app(CommandeService::class)->changerStatut($commande, 'confirmee');
        } catch (\Throwable $e) {
            Log::warning('Confirmation auto apres paiement GoalPay impossible', [
                'commande_id' => $commande->id,
                'erreur' => $e->getMessage(),
            ]);
        }
    }

    private function notifierClient(Commande $commande, Paiement $paiement): void
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
            $dernierMessage = MessageCanal::where('entreprise_id', $commande->entreprise_id)
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
        $items = $commande->items->map(fn ($i) => "{$i->quantite}x {$i->produit_nom}")->implode(', ');

        if (in_array($commande->statut, ['livree', 'expediee'], true)) {
            $texte = "Paiement de {$montant} Ar bien reçu pour votre commande de {$items}."
                ."\nMerci, votre commande est intégralement soldée !";
        } else {
            $texte = "Paiement reçu !\n"
                ."Votre commande de {$items} sera livrée dans quelques heures.\n\n"
                ."Montant réglé : {$montant} Ar";

            if ((float) $commande->reste_a_payer > 0) {
                $texte .= "\nIl reste encore {$reste} Ar à régler.";
            }

            $texte .= "\nMerci pour votre confiance.";
        }

        try {
            app(TelegramService::class)->envoyerMessage($canal, $chatId, $texte);

            MessageCanal::create([
                'entreprise_id' => $commande->entreprise_id,
                'client_id' => $commande->client_id,
                'canal' => 'Telegram',
                'direction' => 'sortant',
                'texte' => $texte,
                'conversation_id' => $chatId,
                'source' => 'auto',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Notification paiement GoalPay client echouee', [
                'commande_id' => $commande->id,
                'erreur' => $e->getMessage(),
            ]);
        }
    }
}
