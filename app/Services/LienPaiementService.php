<?php

namespace App\Services;

use App\Models\CanalEntreprise;
use App\Models\Commande;
use App\Models\Entreprise;
use App\Models\MessageCanal;
use Illuminate\Support\Facades\Log;

/**
 * Envoie un lien de paiement (Stripe / GoalPay) au client dans la discussion
 * d'origine de la commande, quel que soit le canal (Telegram, WhatsApp,
 * Messenger).
 *
 * Le canal est déduit de la commande, puis de la dernière conversation
 * connue du client. Le message est journalisé dans messages_canal afin
 * d'apparaître dans le fil unifié côté tableau de bord.
 */
class LienPaiementService
{
    /** Canaux supportés : libellé => type dans canaux_entreprise. */
    private const CANAUX = [
        'Telegram' => 'telegram',
        'WhatsApp' => 'whatsapp',
        'Messenger' => 'messenger',
    ];

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly WhatsAppService $whatsapp,
        private readonly FacebookMessengerService $messenger,
    ) {}

    /**
     * Crée la session de paiement puis envoie le lien au client.
     *
     * @return array{envoie: bool, canal: ?string, message: ?string}
     */
    public function envoyerGoalpay(Commande $commande, float $montant): array
    {
        $session = app(GoalpayService::class)->creerSessionCheckout($commande, $montant);

        $reste = number_format((float) $commande->reste_a_payer, 0, ',', ' ');
        $montantFormate = number_format($montant, 0, ',', ' ');
        $items = $commande->items->map(fn ($i) => "{$i->quantite}x {$i->produit_nom}")->implode(', ');

        $texte = "Votre commande : {$items}\n"
            ."Montant à régler : {$montantFormate} Ar\n"
            ."Reste à payer : {$reste} Ar\n\n"
            .'Réglez votre commande par Mobile Money (MVola, Airtel Money, Orange Money) '
            .'en touchant le bouton ci-dessous.';

        return $this->envoyerAClient($commande, $texte, null, $session['url']);
    }

    /**
     * Équivalent pour Stripe (carte bancaire).
     *
     * @return array{envoie: bool, canal: ?string, message: ?string}
     */
    public function envoyerStripe(Commande $commande, float $montant): array
    {
        $session = app(StripeService::class)->creerSessionCheckout($commande, $montant);

        $reste = number_format((float) $commande->reste_a_payer, 0, ',', ' ');
        $montantFormate = number_format($montant, 0, ',', ' ');
        $items = $commande->items->map(fn ($i) => "{$i->quantite}x {$i->produit_nom}")->implode(', ');

        $texte = "Votre commande : {$items}\n"
            ."Montant à régler : {$montantFormate} Ar\n"
            ."Reste à payer : {$reste} Ar\n\n"
            .'Réglez votre commande par carte bancaire en touchant le bouton ci-dessous.';

        return $this->envoyerAClient($commande, $texte, $session['url'], null);
    }

    /**
     * Achemine le message vers le bon service de canal.
     *
     * @return array{envoie: bool, canal: ?string, message: ?string}
     */
    private function envoyerAClient(Commande $commande, string $texte, ?string $lienCarte, ?string $lienMobile): array
    {
        $canal = $this->resoudreCanal($commande);

        if (! $canal) {
            return ['envoye' => false, 'canal' => null, 'message' => 'Aucune conversation client disponible.'];
        }

        $libelle = $canal['libelle'];
        $connexion = $canal['connexion'];
        $conversationId = $canal['conversation_id'];

        try {
            $result = match ($libelle) {
                'Telegram' => $this->telegram->envoyerMessage($connexion, $conversationId, $texte, $lienCarte, $lienMobile),
                'WhatsApp' => $this->whatsapp->envoyerMessage($connexion, $conversationId, $texte, $lienCarte, $lienMobile),
                'Messenger' => $this->messenger->envoyerMessage($connexion, $conversationId, $texte, $lienCarte, $lienMobile),
            };

            if (! ($result['ok'] ?? false)) {
                Log::warning('Lien de paiement non livre', [
                    'commande_id' => $commande->id,
                    'canal' => $libelle,
                    'erreur' => $result['error'] ?? 'inconnue',
                ]);

                return ['envoye' => false, 'canal' => $libelle, 'message' => "Le canal {$libelle} a refusé l'envoi."];
            }
        } catch (\Throwable $e) {
            Log::warning('Envoi lien de paiement echoue', [
                'commande_id' => $commande->id,
                'canal' => $libelle,
                'erreur' => $e->getMessage(),
            ]);

            return ['envoye' => false, 'canal' => $libelle, 'message' => $e->getMessage()];
        }

        MessageCanal::create([
            'entreprise_id' => $commande->entreprise_id,
            'client_id' => $commande->client_id,
            'canal' => $libelle,
            'direction' => 'sortant',
            'texte' => $texte,
            'conversation_id' => $conversationId,
            'source' => 'manuel',
        ]);

        return ['envoye' => true, 'canal' => $libelle, 'message' => null];
    }

    /**
     * Retrouve le canal connecté et l'identifiant de conversation du client.
     *
     * Ordre : canal de la commande → canal préféré du client → dernière
     * conversation connue. On retombe toujours sur le dernier MessageCanal,
     * qui porte la conversation_id réelle.
     *
     * @return array{libelle: string, connexion: CanalEntreprise, conversation_id: string}|null
     */
    private function resoudreCanal(Commande $commande): ?array
    {
        $entreprise = $commande->entreprise;

        $candidats = array_values(array_filter([
            $commande->canal,
            $commande->client?->canal_prefere,
        ]));

        foreach ($candidats as $libelle) {
            $connexion = $this->connexionActive($entreprise, $libelle);

            if (! $connexion) {
                continue;
            }

            $conversationId = $this->conversationId($commande, $libelle);

            if ($conversationId) {
                return [
                    'libelle' => $libelle,
                    'connexion' => $connexion,
                    'conversation_id' => $conversationId,
                ];
            }
        }

        // Dernier recours : le canal du dernier message échangé avec ce client.
        $dernier = MessageCanal::where('entreprise_id', $commande->entreprise_id)
            ->where('client_id', $commande->client_id)
            ->whereNotNull('conversation_id')
            ->latest()
            ->first();

        if (! $dernier) {
            return null;
        }

        $connexion = $this->connexionActive($entreprise, $dernier->canal);

        if (! $connexion || ! $dernier->conversation_id) {
            return null;
        }

        return [
            'libelle' => $dernier->canal,
            'connexion' => $connexion,
            'conversation_id' => $dernier->conversation_id,
        ];
    }

    private function connexionActive(Entreprise $entreprise, string $libelle): ?CanalEntreprise
    {
        $type = self::CANAUX[$libelle] ?? null;

        if (! $type) {
            return null;
        }

        return $entreprise->canaux()->where('type', $type)->where('actif', true)->first();
    }

    /**
     * conversation_id = identifiant réel côté canal (chat_id, PSID,
     * numéro de téléphone). On privilégie l'identifiant stocké au moment des
     * échanges, puis le pivot client_entreprise.
     */
    private function conversationId(Commande $commande, string $libelle): ?string
    {
        $dernier = MessageCanal::where('entreprise_id', $commande->entreprise_id)
            ->where('client_id', $commande->client_id)
            ->where('canal', $libelle)
            ->whereNotNull('conversation_id')
            ->latest()
            ->first();

        if ($dernier?->conversation_id) {
            return $dernier->conversation_id;
        }

        $pivot = $commande->client?->entreprises()
            ->where('entreprises.id', $commande->entreprise_id)
            ->first()?->pivot;

        $plateforme = ['Telegram' => 'telegram', 'WhatsApp' => 'whatsapp', 'Messenger' => 'facebook'][$libelle] ?? null;

        if ($plateforme && ($pivot?->plateforme_sociale === $plateforme) && $pivot?->identifiant_social) {
            return (string) $pivot->identifiant_social;
        }

        return $commande->client?->identifiant_externe ?: null;
    }
}
