<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Entreprise;
use App\Models\MessageCanal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Génération des réponses conversationnelles via une API IA compatible OpenAI.
 *
 * Provider par défaut : Google Gemini (palier gratuit). Le service est
 * volontairement agnostique : la config `services.ia` permet de changer de
 * fournisseur sans toucher au code.
 *
 * Principe : l'IA ne fait que la CONVERSATION. Toute intention transactionnelle
 * (commande, paiement, annulation, suivi) reste traitée par ReponseAutomatiqueService
 * et ses règles, donc jamais déléguée au modèle.
 *
 * Garde-fou commercialement critique : le modèle reçoit le catalogue réel et on
 * revérifie chaque montant et chaque stock qu'il cite. Si un prix ou un stock
 * annoncé ne correspond pas à la base, la réponse est rejetée au profit des règles.
 */
class ReponseIaService
{
    /**
     * Délai maximal d'attente de la réponse. Au-delà on renvoie les règles.
     *
     * Mesures du 06/10/2026 sur gemini-3.5-flash-lite : la distribution est
     * bimodale, soit ~1,5 s, soit au-delà du délai. Aucun gain au-delà de 5 s
     *   5 s  -> 63% de reussites, echec rendu en 5 s
     *   12 s -> 63%, echec rendu en 12 s
     *   25 s -> 63%, echec rendu en 25 s
     * On choisit donc 5 s : meme taux de reussite, mais le client n'attend pas
     * 12 secondes avant de recevoir une reponse des regles.
     */
    private const TIMEOUT_SECONDES = 5;

    /**
     * Nombre de messages d'historique envoyés au modèle.
     */
    private const HISTORIQUE = 8;

    /**
     * Délai effectif, surchargeable par services.ia.timeout.
     */
    private function timeout(): int
    {
        return max(1, (int) config('services.ia.timeout', self::TIMEOUT_SECONDES));
    }

    public function estConfigure(): bool
    {
        $cle = (string) config('services.ia.api_key', '');

        return $cle !== ''
            && ! in_array($cle, ['xai-xxxxx', 'AIzaSyA-votre-cle'], true);
    }

    /**
     * Répond à un message conversationnel.
     *
     * @return array{message: string, source: 'ia'|'regles'}|null
     *                                                            null si le modèle est indisponible ou si sa réponse est douteuse.
     */
    public function repondre(
        Entreprise $entreprise,
        Client $client,
        string $message,
        Collection $produits,
        string $canal,
    ): ?array {
        if (! $this->estConfigure()) {
            return null;
        }

        try {
            $contenu = $this->appeler($entreprise, $client, $message, $produits, $canal);
        } catch (\Throwable $e) {
            Log::warning('IA : appel API echoue, repli sur les regles', [
                'entreprise_id' => $entreprise->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if ($contenu === null || trim($contenu) === '') {
            return null;
        }

        // Le modèle ne doit jamais promettre un prix ou un stock invente.
        if (! $this->chiffresVerifies($contenu, $produits)) {
            Log::warning('IA : reponse rejetee (montant ou stock non verifie)', [
                'entreprise_id' => $entreprise->id,
                'extrait' => mb_substr($contenu, 0, 200),
            ]);

            return null;
        }

        // Trace utile au diagnostic : permet de savoir en production si une
        // reponse vient bien du modele ou des regles, sans relire le code.
        Log::info('IA : reponse du modele utilisee', [
            'entreprise_id' => $entreprise->id,
            'canal' => $canal,
            'modele' => config('services.ia.model'),
            'reponse' => mb_substr(trim($contenu), 0, 160),
        ]);

        return ['message' => trim($contenu), 'source' => 'ia'];
    }

    /**
     * Appel réel à l'API (compatible OpenAI : Gemini, xAI, Mistral...).
     */
    private function appeler(
        Entreprise $entreprise,
        Client $client,
        string $message,
        Collection $produits,
        string $canal,
    ): ?string {
        $reponse = Http::timeout($this->timeout())
            ->acceptJson()
            ->withToken((string) config('services.ia.api_key'))
            ->post(rtrim((string) config('services.ia.base_url'), '/').'/chat/completions', [
                'model' => config('services.ia.model'),
                'temperature' => (float) config('services.ia.temperature', 0.3),
                'max_tokens' => (int) config('services.ia.max_tokens', 1024),
                'messages' => $this->messages($entreprise, $client, $message, $produits, $canal),
            ]);

        if (! $reponse->successful()) {
            Log::warning('IA : reponse API en erreur', [
                'modele' => config('services.ia.model'),
                'status' => $reponse->status(),
                'corps' => mb_substr($reponse->body(), 0, 300),
            ]);

            return null;
        }

        return $reponse->json('choices.0.message.content');
    }

    /**
     * Construit le prompt : consigne de vente + faits vérifiés + historique court.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function messages(
        Entreprise $entreprise,
        Client $client,
        string $message,
        Collection $produits,
        string $canal,
    ): array {
        $messages = [['role' => 'system', 'content' => $this->consigne($entreprise, $produits, $canal)]];

        foreach ($this->historique($client, $entreprise, $canal) as $tour) {
            $messages[] = $tour;
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        return $messages;
    }

    /**
     * Consigne système. Elle interdit explicitement d'inventer un prix, un stock
     * ou une promotion : toute donnée non fournie doit être déclarée indisponible.
     */
    private function consigne(Entreprise $entreprise, Collection $produits, string $canal): string
    {
        $lignes = $produits->map(fn ($p) => sprintf(
            '- %s : %s Ar (stock : %d)',
            $p->nom,
            number_format((float) $p->prix, 0, ',', ' '),
            $p->stock,
        ))->implode("\n");

        $regles = [
            "Tu es l'assistant commercial de la boutique « {$entreprise->nom} » sur $canal.",
            'Tu discutes avec le client en français, ton chaleureux et concis (2 phrases maximum).',
            '',
            'CATALOGUE (seule source de vérité pour les prix et les stocks) :',
            $lignes !== '' ? $lignes : '- aucun produit en catalogue',
            '',
            'RÈGLES ABSOLUES :',
            '- N\'invente JAMAIS un prix, un stock, une promotion ou une livraison.',
            '- Si une information n\'est pas dans le catalogue ou le contexte, dis que tu vérifies avec le vendeur.',
            '- Ne propose PAS de lien de paiement et n\'invente pas de lien : les boutons de paiement sont ajoutés automatiquement.',
            '- Si le client veut commander, demande le produit et la quantité, le vendeur finalise.',
            '- Si le client veut annuler ou modifier une commande, dis qu\'un vendeur doit intervenir.',
            '',
            $entreprise->telephone ? "Téléphone de la boutique : {$entreprise->telephone}" : '',
            '',
            'Rédige uniquement la réponse à envoyer au client, sans préambule ni guillemets.',
        ];

        return trim(implode("\n", array_filter($regles, fn ($l) => $l !== '')));
    }

    /**
     * Historique court du client, cloisonné sur l'entreprise ET le canal.
     *
     * Le cloisonnement par entreprise est une exigence de confidentialité : un
     * même client peut être rattaché à plusieurs boutiques, et on ne doit jamais
     * envoyer à un modèle les messages d'une autre entreprise que celle qui
     * répond. On ne mélange pas non plus WhatsApp et Telegram.
     */
    private function historique(Client $client, Entreprise $entreprise, string $canal): array
    {
        $messages = MessageCanal::where('client_id', $client->id)
            ->where('entreprise_id', $entreprise->id)
            ->whereRaw('lower(canal) = ?', [mb_strtolower($canal)])
            ->whereIn('direction', ['entrant', 'sortant'])
            ->orderByDesc('created_at')
            ->limit(self::HISTORIQUE)
            ->get(['direction', 'texte'])
            ->reverse();

        return $messages->map(fn (MessageCanal $m) => [
            'role' => $m->direction === 'entrant' ? 'user' : 'assistant',
            // On retire les anciens liens de paiement : ils sont périmés et
            // polluent le contexte du modèle.
            'content' => preg_replace(
                '#https?://\S*(goalpay|stripe|checkout)[^\s]*#i',
                '[lien de paiement]',
                $m->texte,
            ) ?? $m->texte,
        ])->all();
    }

    /**
     * Vérifie que chaque montant en Ariary cité par le modèle correspond à un
     * prix réellement présent au catalogue, et chaque stock à un stock réel.
     *
     * Un montant en devise (€ / EUR) est refusé : les paiements passent par des
     * liens dédiés, le modèle n'a pas à annoncer de conversion.
     */
    private function chiffresVerifies(string $contenu, Collection $produits): bool
    {
        if (! config('services.ia.verifier_montants', true)) {
            return true;
        }

        if (preg_match('/€|\bEUR\b|\beuro/i', $contenu)) {
            return false;
        }

        // Tous les montants cités doivent exister au catalogue.
        preg_match_all('/([0-9][0-9\s.,]*)\s*(?:Ar|ariary|FMG|mg)/i', $contenu, $trouves);

        if (! empty($trouves[1])) {
            $prixConnus = $produits
                ->map(fn ($p) => (int) round((float) $p->prix))
                ->all();

            foreach ($trouves[1] as $brut) {
                $valeur = (int) preg_replace('/[^0-9]/', '', $brut);

                if ($valeur === 0) {
                    continue;
                }

                if (! in_array($valeur, $prixConnus, true)) {
                    return false;
                }
            }
        }

        // Même exigence sur les stocks : « il reste 3 pièces » doit correspondre
        // à un stock réellement enregistré, sinon on ne peut pas l'affirmer.
        if (preg_match_all(
            '/\b(?:en stock|stock|rest(?:e|es|ent)|disponible)\s*:?\s*'
                .'(?:que|de|seulement|plus|un|une)?\s*(\d+)\s*(Ar|ariary)?/iu',
            $contenu,
            $stocks
        ) && ! empty($stocks[1])) {
            $stocksConnus = $produits
                ->map(fn ($p) => (int) $p->stock)
                ->all();

            foreach ($stocks[1] as $index => $brut) {
                // « stock : 4 000 Ar » est un prix, pas un stock : il a déjà
                // été validé par le contrôle des montants ci-dessus.
                if (($stocks[2][$index] ?? '') !== '') {
                    continue;
                }

                if (! in_array((int) $brut, $stocksConnus, true)) {
                    return false;
                }
            }
        }

        return true;
    }
}
