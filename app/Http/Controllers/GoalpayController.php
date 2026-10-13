<?php

namespace App\Http\Controllers;

use App\Models\Commande;
use App\Models\Entreprise;
use App\Services\GoalpayService;
use App\Services\LienPaiementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoalpayController extends Controller
{
    public function __construct(
        private readonly GoalpayService $goalpay,
        private readonly LienPaiementService $liens,
    ) {
    }

    /**
     * POST /entreprises/{entreprise}/commandes/{commande}/goalpay/session
     * Body: { "montant": 50000 }
     * Créé une session GoalPay et renvoie le lien Mobile Money.
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

        if (! $this->goalpay->estConfigure()) {
            return response()->json(['message' => 'Paiement GoalPay non disponible pour le moment.'], 503);
        }

        try {
            $session = $this->goalpay->creerSessionCheckout($commande, $montant);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($session);
    }

    /**
     * POST /entreprises/{entreprise}/commandes/{commande}/goalpay/envoyer
     * Body: { "montant": 50000 }
     * Crée la session ET envoie le lien Mobile Money au client dans sa
     * discussion d'origine (Telegram, WhatsApp, Messenger ou Instagram).
     */
    public function envoyerLien(Request $request, Entreprise $entreprise, Commande $commande): JsonResponse
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

        if (! $this->goalpay->estConfigure()) {
            return response()->json(['message' => 'Paiement GoalPay non disponible pour le moment.'], 503);
        }

        try {
            $resultat = $this->liens->envoyerGoalpay($commande, $montant);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($resultat, $resultat['envoye'] ? 201 : 422);
    }

    /**
     * POST /webhooks/goalpay — PUBLIC, appelé par GoalPay.
     */
    public function webhook(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = $request->header('x-gpay-signature', '');

        try {
            $this->goalpay->traiterWebhook($rawBody, $signature);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('GoalPay webhook error', ['message' => $e->getMessage()]);
            return response()->json(['ok' => false], 400);
        }

        return response()->json(['ok' => true]);
    }
}
