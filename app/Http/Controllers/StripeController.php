<?php

namespace App\Http\Controllers;

use App\Models\Commande;
use App\Models\Entreprise;
use App\Models\Paiement;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StripeController extends Controller
{
    public function __construct(private readonly StripeService $stripe)
    {
    }

    /**
     * POST /entreprises/{entreprise}/commandes/{commande}/stripe/session
     * Body: { "montant": 50000 }
     * Créé une Checkout Session et renvoie le lien de paiement.
     */
    public function creerSession(Request $request, Entreprise $entreprise, Commande $commande): JsonResponse
    {
        $this->authorize('gererCommandes', $entreprise);
        abort_if($commande->entreprise_id !== $entreprise->id, 404);

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:0.01'],
        ]);

        $montant = (float) $data['montant'];
        $reste = (float) $commande->reste_a_payer;

        if ($montant > $reste + 0.01) {
            return response()->json(['message' => "Le montant dépasse le reste à payer ({$reste} Ar)."], 422);
        }

        if (! $this->stripe->estConfigure()) {
            return response()->json(['message' => 'Paiement par carte non disponible pour le moment.'], 503);
        }

        try {
            $session = $this->stripe->creerSessionCheckout($commande, $montant);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($session);
    }

    /**
     * POST /webhooks/stripe  — PUBLIC, appelé par Stripe.
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature', '');

        try {
            $this->stripe->traiterWebhook($payload, $signature);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Stripe webhook error', ['message' => $e->getMessage()]);
            return response()->json(['ok' => false], 400);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/paiements/stripe/retour?session_id=...
     * — PUBLIC. Est le redirect URL d'une Checkout Session Stripe.
     * Le client revient ici après le paiement : on vérifie la session côté serveur,
     * on enregistre le paiement (payé), puis on le renvoie sur la page de confirmation.
     */
    public function retour(Request $request): \Illuminate\Http\RedirectResponse
    {
        $sessionId = (string) $request->query('session_id', '');
        $annule = false;

        if ($sessionId) {
            try {
                $session = $this->stripe->retrouverSession($sessionId);

                if (($session->payment_status ?? '') === 'paid') {
                    $this->stripe->enregistrerPaiementSession([
                        'id' => (string) $session->id,
                        'amount_total' => $session->amount_total ?? 0,
                        'payment_intent' => $session->payment_intent ?? null,
                        'metadata' => $session->metadata?->toArray() ?? [],
                    ]);
                } else {
                    $annule = true;
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Stripe retour : session invalide', [
                    'session_id' => $sessionId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return redirect()->to(
            '/paiements/stripe/succes?session_id=' . urlencode($sessionId) . ($annule ? '&annule=1' : '')
        );
    }

    /**
     * GET /paiements/stripe/succes — PUBLIC.
     * Page « Merci + facture » affichée au client après le paiement.
     */
    public function succes(Request $request): \Illuminate\View\View
    {
        $sessionId = (string) $request->query('session_id', '');

        $paiement = $this->trouverPaiementParSession($sessionId);

        if (! $paiement || $paiement->statut !== 'paye') {
            return view('stripe.annule', ['lienConversation' => null]);
        }

        $commande = $paiement->commande->loadMissing('items', 'client', 'entreprise', 'facture');

        $reste = (float) $commande->reste_a_payer;
        $lienSolde = null;

        if ($reste > 0 && $this->stripe->estConfigure()) {
            try {
                $sessionData = $this->stripe->creerSessionCheckout($commande, $reste);
                $lienSolde = $sessionData['url'];
            } catch (\Throwable $e) {
                $lienSolde = null;
            }
        }

        return view('stripe.succes', [
            'paiement' => $paiement,
            'commande' => $commande,
            'reste' => $reste,
            'lienSolde' => $lienSolde,
            'lienConversation' => $this->lienConversation($commande->entreprise_id),
            'lienFacture' => $paiement->commande->facture
                ? '/paiements/stripe/succes/facture?session_id=' . urlencode($sessionId)
                : null,
        ]);
    }

    /**
     * GET /paiements/stripe/succes/facture?session_id=... — PUBLIC.
     * Télécharge la facture PDF correspondant à un paiement payé.
     */
    public function facturePdf(Request $request)
    {
        $sessionId = (string) $request->query('session_id', '');

        $paiement = $this->trouverPaiementParSession($sessionId);
        $facture = $paiement?->commande?->facture;

        if (! $paiement || $paiement->statut !== 'paye' || ! $facture || ! Storage::disk('public')->exists($facture->chemin_pdf)) {
            abort(404);
        }

        return Storage::disk('public')->download($facture->chemin_pdf, "{$facture->numero}.pdf");
    }

    private function trouverPaiementParSession(string $sessionId): ?Paiement
    {
        return Paiement::with('commande')->where('stripe_session_id', $sessionId)->first();
    }

    /**
     * Lien « retour à la conversation » (ex: t.me/...), s'il est disponible.
     */
    private function lienConversation(int $entrepriseId): ?string
    {
        try {
            $canal = \App\Models\CanalEntreprise::where('entreprise_id', $entrepriseId)
                ->where('type', 'telegram')
                ->where('actif', true)
                ->first();

            return $canal?->bot_username ? "https://t.me/{$canal->bot_username}" : null;
        } catch (\Throwable) {
            return null;
        }
    }
}