<?php

namespace App\Services;

use App\Models\Commande;
use App\Models\Paiement;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PaiementService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly TelegramService $telegram,
        private readonly OcrService $ocr,
    ) {}

    public function enregistrerPaiement(
        Commande $commande,
        float $montant,
        string $methode,
        ?string $reference = null,
        ?UploadedFile $preuveImage = null,
        ?string $idempotencyKey = null,
    ): Paiement {
        if ($idempotencyKey) {
            $existant = $commande->paiements()->where('idempotency_key', $idempotencyKey)->first();

            if ($existant) {
                return $existant;
            }
        }

        $preuveChemin = null;
        $preuveVerifiee = null;
        $montantDetecte = null;
        $ocrTexte = null;
        $verificationMessage = null;

        if ($preuveImage) {
            $preuveChemin = $preuveImage->store('paiements', 'public');

            $verification = $this->ocr->verifierMontant($preuveImage, $montant);
            $preuveVerifiee = $verification['match'];
            $montantDetecte = $verification['montant_detecte'];
            $ocrTexte = $verification['texte'] ?? null;
            $verificationMessage = $verification['message'];
        }

        // Logique statut :
        // - Pas de photo          → paye (pas de verification)
        // - Photo, OCR OK, match  → paye (verifie)
        // - Photo, OCR lit mais ne matche pas → en_attente (a valider)
        // - Photo, OCR ne lit rien → paye avec warning (benefice du doute)
        if ($preuveImage && $preuveVerifiee === false && !empty(trim($ocrTexte ?? ''))) {
            $statut = 'en_attente';
            $payeLe = null;
        } else {
            $statut = 'paye';
            $payeLe = now();
        }

        $commissionPct = $commande->entreprise->commissionPct();
        $commission = round($montant * $commissionPct / 100, 2);
        $montantReverse = round($montant - $commission, 2);

        try {
            $paiement = $commande->paiements()->create([
                'montant' => $montant,
                'methode' => $methode,
                'reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'preuve_image' => $preuveChemin,
                'preuve_verifiee' => $preuveVerifiee,
                'montant_detecte' => $montantDetecte,
                'ocr_texte' => $ocrTexte,
                'verification_message' => $verificationMessage,
                'commission_pct' => $commissionPct,
                'commission_relancia' => $commission,
                'montant_reverse' => $montantReverse,
                'statut' => $statut,
                'paye_le' => $payeLe,
            ]);
        } catch (QueryException $e) {
            // Deux requêtes avec la même clé en parallèle : la contrainte unique
            // ne laisse passer qu'une seule création, on renvoie le paiement existant.
            if ($idempotencyKey && $this->estViolationUnique($e)) {
                $existant = $commande->paiements()->where('idempotency_key', $idempotencyKey)->first();

                if ($existant) {
                    return $existant;
                }
            }

            throw $e;
        }

        if ($statut === 'paye') {
            app(RelanciaComptabiliteService::class)->encaisser($paiement);
        }

        $this->notifications->paiementRecu($paiement);

        if ($statut === 'paye') {
            $this->envoyerNotificationClient($commande, $paiement);
        }

        return $paiement;
    }

    public function montantPaye(Commande $commande): float
    {
        return (float) $commande->paiements->where('statut', 'paye')->sum('montant');
    }

    public function resteAPayer(Commande $commande): float
    {
        return max(0, (float) $commande->montant_total - $this->montantPaye($commande));
    }

    public function peutConfirmer(Commande $commande): bool
    {
        $pct = $commande->entreprise->acompte_pct ?? 50;
        $montantRequis = (float) $commande->montant_total * $pct / 100;

        return $this->montantPaye($commande) >= $montantRequis;
    }

    private function envoyerNotificationClient(Commande $commande, Paiement $paiement): void
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
        $texte = "Votre paiement de {$montant} Ar a bien été reçu. Merci !";

        try {
            $this->telegram->envoyerMessage($canal, $chatId, $texte);

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
            Log::warning('Notification paiement client Telegram echouee', [
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
