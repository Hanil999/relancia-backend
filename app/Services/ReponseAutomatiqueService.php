<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Commande;
use App\Models\Entreprise;
use App\Models\Produit;
use Illuminate\Support\Collection;

/**
 * Moteur de réponse automatique aux messages clients (Telegram, WhatsApp, …) :
 * reçoit le texte d'un message client et produit la réponse que l'entreprise
 * enverrait :
 *   - questions : présentation du catalogue, affichage des prix ;
 *   - commande   : vérification du stock, création + confirmation automatique
 *                  (stock décrémenté, facture générée), notification interne.
 */
class ReponseAutomatiqueService
{
    public function __construct(
        private readonly CommandeParserService $parser,
        private readonly CommandeService $commandes,
        private readonly StripeService $stripe,
    ) {
    }

    /**
     * @return array{
     *     message: string,
     *     suggestions: array<int, string>,
     *     produits: array<int, array{id: int, nom: string, prix: float, stock: int}>|null,
     *     commande: array{numero: string, montant_total: float, statut: string, statut_label: string, facture_numero: ?string}|null,
     *     ruptures: array<int, string>,
     *     client: array{id: int, nom: string},
     * }
     */
    public function repondre(Entreprise $entreprise, Client $client, string $message, string $canal = 'Messenger'): array
    {
        $produits = $entreprise->produits()->get();
        $texte = $this->texteNormalise($message);

        $base = [
            'client' => ['id' => $client->id, 'nom' => $client->nom],
            'produits' => null,
            'commande' => null,
            'ruptures' => [],
        ];

        // Messages trop courts ou vides → pas de commande
        if (mb_strlen($texte) < 2) {
            return [...$base, ...$this->traiterQuestion($message, $produits)];
        }

        // --- 1. Intentions spécifiques AVANT le filtrage chat ---
        // (ex: "je n'arrive pas à payer" contient "pas" mais reste une demande de paiement)
        if ($this->contient($texte, ['annuler', 'cancel', 'supprimer', 'effacer'])) {
            return [...$base, ...$this->traiterAnnulation($entreprise, $client)];
        }

        if ($this->contient($texte, ['modifier', 'changer', 'remplacer'])) {
            return [...$base, ...$this->traiterModification($entreprise, $client, $message, $canal)];
        }

        if ($this->contient($texte, ['paiement', 'payer', 'paie', 'virement', 'mobile money', 'mvola', 'airtel money', 'orange money', 'carte bancaire', 'cb', 'stripe', 'paiement en ligne'])) {
            return [...$base, ...$this->traiterPaiement($client, $canal)];
        }

        if ($this->contient($texte, ['livraison', 'livrer', 'livre', 'délai', 'delai', 'recevoir', 'expedition', 'expédition'])) {
            return [...$base, ...$this->traiterLivraison($client)];
        }

        // --- 2. Chat pur (remerciements, acquiescements, négations, au revoir) ---
        if ($this->contient($texte, [
            'merci', 'ok', 'okay', 'super', 'parfait', 'excellent', 'genial', 'bravo',
            'compris', 'noté', 'note', 'c\'est bon', 'entendu',
            'oui', 'yes', 'yeah', 'si', 'certes',
            'jamais', 'rien', 'a bientot', 'au revoir', 'bonne journee', 'bonne soiree', 'a plus',
            'ah', 'euh', 'hmm', 'haha', 'lol', 'mdr',
        ])) {
            // Uniquement si rien d'autre ne ressemble à une commande et que le message est court
            if (! $this->contientMotCommande($texte) && ! $this->contientCatalogue($texte) && mb_strlen($texte) <= 50) {
                return [...$base, ...$this->traiterAcquiescement()];
            }
        }

        // --- 3. Questions spécifiques ---
        // Catalogue / produits AVANT les salutations (ex: "Bonjour, voyons les produits")
        if ($this->contientCatalogue($texte)) {
            return [...$base, ...$this->traiterQuestion($message, $produits)];
        }

        if ($this->contient($texte, [
            'bonjour', 'bonsoir', 'hello', 'coucou', 'slt', 'cc', 'bienvenue', 'bonne nuit',
        ])) {
            return [...$base, ...$this->traiterQuestion($message, $produits)];
        }

        if ($this->contient($texte, ['prix', 'tarif', 'combien', 'coute', 'coût', 'cout', 'cher', 'moins cher'])) {
            return [...$base, ...$this->traiterQuestion($message, $produits)];
        }

        // --- 4. Commande : parser SEULEMENT si le message contient un intent de commande ---
        if ($this->contientMotCommande($texte)) {
            $analyse = $this->parser->parser($message, $produits);

            if (! empty($analyse['reconnus'])) {
                return [...$base, ...$this->traiterCommande($entreprise, $client, $message, $canal, $analyse['reconnus'])];
            }

            return [...$base, 'message' => "Je n'ai pas reconnu le produit demandé. Voici notre catalogue :", ...$this->repondreCatalogue($produits)];
        }

        // --- 5. Fallback : question / aide ---
        return [...$base, ...$this->traiterQuestion($message, $produits)];
    }

    private function traiterAcquiescement(): array
    {
        return [
            'message' => "De rien ! 😊 N'hésitez pas si vous avez besoin d'aide.",
            'suggestions' => ['Voir les produits', 'Afficher les prix'],
        ];
    }

    /**
     * Une commande a été reconnue : on vérifie le stock, puis on crée la commande
     * et on la confirme automatiquement (stock prélevé + facture générée).
     *
     * @param  array<int, array{produit: Produit, quantite: int, confiance: float}>  $lignes
     */
    private function traiterCommande(Entreprise $entreprise, Client $client, string $message, string $canal, array $lignes): array
    {
        $ruptures = [];
        foreach ($lignes as $ligne) {
            $produit = $ligne['produit'];
            if ($produit->stock < $ligne['quantite']) {
                $ruptures[] = "{$produit->nom} (stock : {$produit->stock})";
            }
        }

        if (! empty($ruptures)) {
            $produits = $entreprise->produits()->get();

            return [
                'message' => "Désolé, certains produits ne sont pas disponibles en quantité suffisante : "
                    . implode(', ', $ruptures) . '.',
                'suggestions' => $this->suggestions($produits),
                'ruptures' => $ruptures,
            ];
        }

        $commande = $this->commandes->creerDepuisMessage($entreprise, $client, $message, $canal)['commande'];

        $lignes = $commande->items->map(function ($item) {
            return $item->quantite . ' ' . $item->produit_nom;
        });

        $count = $lignes->count();
        if ($count === 1) {
            $listeProduits = $lignes->first();
        } elseif ($count === 2) {
            $listeProduits = $lignes->first() . ' et ' . $lignes->last();
        } else {
            $dernier = $lignes->pop();
            $listeProduits = $lignes->implode(', ') . ' et ' . $dernier;
        }

        $montantTotal = number_format((float) $commande->montant_total, 0, ',', ' ');
        $pct = $entreprise->acompte_pct ?? 50;
        $montantAcompte = number_format((float) $commande->montant_total * $pct / 100, 0, ',', ' ');
        $numeroTelephone = $entreprise->telephone ?: '034XXXXXXXX';

        $texte = "Votre commande de {$listeProduits} est bien enregistrée !\n"
            . "Montant total : {$montantTotal} Ar\n"
            . "Acompte demandé : {$montantAcompte} Ar ({$pct}%)\n\n"
            . "Pour confirmer, payez votre acompte via :\n";

        $lienPaiement = $this->lienPaiementStripe($commande, (float) $commande->montant_total * $pct / 100);
        $texte .= $this->ligneCarte($lienPaiement, $canal);

        $texte .= "- Mobile Money (MVola / Airtel Money) au {$numeroTelephone}\n"
            . "- En boutique\n\n"
            . "Envoyez la preuve de paiement ici ou informez le vendeur.\n"
            . "Merci !";

        return [
            'message' => trim($texte),
            'lien_paiement' => $lienPaiement,
            'suggestions' => $this->suggestions($entreprise->produits()->get()),
            'commande' => [
                'numero' => $commande->numero,
                'montant_total' => (float) $commande->montant_total,
                'statut' => $commande->statut,
                'statut_label' => $commande->statut_label,
                'facture_numero' => null,
            ],
            'ruptures' => [],
        ];
    }

    /**
     * Aucune commande reconnue : on essaie de répondre à une question
     * (bonjour, catalogue, prix) puis on retombe sur une aide générique.
     *
     * @param  Collection<int, Produit>  $produits
     */
    private function traiterQuestion(string $message, Collection $produits): array
    {
        $texte = $this->texteNormalise($message);

        // 1. Catalogue / produits (AVANT les salutations : "Bonjour, voir les produits" → catalogue)
        if ($this->contientCatalogue($texte)) {
            return $this->repondreCatalogue($produits);
        }

        // 2. Prix
        if ($this->contient($texte, ['prix', 'tarif', 'combien', 'coute', 'coût', 'cout'])) {
            $analyse = $this->parser->parser($message, $produits);

            if (count($analyse['reconnus']) === 1) {
                $produit = $analyse['reconnus'][0]['produit'];

                return [
                    'message' => "Le prix de {$produit->nom} est de "
                        . number_format((float) $produit->prix, 0, ',', ' ') . ' Ar'
                        . ($produit->stock > 0 ? " (stock : {$produit->stock})." : '.'),
                    'suggestions' => $this->suggestions($produits),
                    'produits' => [$this->formatProduit($produit)],
                ];
            }

            return $this->repondrePrix($produits);
        }

        // 3. Salutations
        if ($this->contient($texte, ['bonjour', 'salut', 'bonsoir', 'hello', 'coucou', 'slt', 'cc', 'bienvenue', 'bonne nuit'])) {
            return [
                'message' => "Bonjour ! 👋 Bienvenue chez nous. Je peux vous montrer nos produits, vous donner les prix ou enregistrer votre commande. Que souhaitez-vous ?",
                'suggestions' => $this->suggestions($produits),
            ];
        }

        return [
            'message' => "Je n'ai pas bien compris votre demande. Voici ce que je peux faire pour vous :",
            'suggestions' => $this->suggestions($produits),
        ];
    }

    /**
     * Minuscules et sans accents — pour des correspondances par mots-clés
     * insensibles aux accents (« achète » ≡ « achete », « journée » ≡ « journee »).
     */
    private function texteNormalise(string $message): string
    {
        $texte = mb_strtolower(trim($message));
        $transliterations = [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u',
            'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ];

        return strtr($texte, $transliterations);
    }

    /**
     * Mots-clés indiquant que le client veut voir le catalogue / les produits.
     */
    private function contientCatalogue(string $texte): bool
    {
        return $this->contient($texte, [
            'produit', 'produits', 'catalogue', 'menu', 'la liste', 'liste',
            'voir les produits', 'voir vos produits', 'montre', 'montre-moi',
            'montrez', 'montrez-moi', 'disponible', 'quoi', 'offre', 'vend',
            'vendez', 'magasin', 'stock', 'qu\'est-ce que vous vendez',
        ]);
    }

    /**
     * @param  Collection<int, Produit>  $produits
     */
    private function repondreCatalogue(Collection $produits): array
    {
        if ($produits->isEmpty()) {
            return [
                'message' => 'Notre catalogue est vide pour le moment.',
                'suggestions' => ['Voir les produits', 'Afficher les prix'],
                'produits' => [],
            ];
        }

        $lignes = $produits->take(10)->map(
            fn (Produit $p) => '• ' . $p->nom . ' — ' . number_format((float) $p->prix, 0, ',', ' ') . ' Ar'
        )->join("\n");

        return [
            'message' => "Voici nos produits :\n{$lignes}\n\nVous pouvez commander directement en écrivant par exemple « je veux 2 colliers ».",
            'suggestions' => $this->suggestions($produits),
            'produits' => $produits->take(10)->map(fn (Produit $p) => $this->formatProduit($p))->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Produit>  $produits
     */
    private function repondrePrix(Collection $produits): array
    {
        if ($produits->isEmpty()) {
            return [
                'message' => 'Notre catalogue est vide pour le moment.',
                'suggestions' => ['Voir les produits'],
                'produits' => [],
            ];
        }

        $lignes = $produits->take(10)->map(
            fn (Produit $p) => '• ' . $p->nom . ' : ' . number_format((float) $p->prix, 0, ',', ' ') . ' Ar'
        )->join("\n");

        return [
            'message' => "Voici nos prix :\n{$lignes}",
            'suggestions' => $this->suggestions($produits),
            'produits' => $produits->take(10)->map(fn (Produit $p) => $this->formatProduit($p))->values()->all(),
        ];
    }

    /**
     * Suggestions cliquables pour le client : questions génériques + commandes rapides.
     *
     * @param  Collection<int, Produit>  $produits
     * @return array<int, string>
     */
    private function suggestions(Collection $produits): array
    {
        $suggestions = ['Voir les produits', 'Afficher les prix'];

        foreach ($produits->take(3) as $produit) {
            $suggestions[] = "Commander 1 {$produit->nom}";
        }

        return $suggestions;
    }

    /**
     * Vérifie si le message contient des mots indiquant une intention de commande.
     * Empêche les faux ordres créés à partir de messages comme "merci", "ok", "oui", etc.
     */
    private function contientMotCommande(string $texte): bool
    {
        return $this->contient($texte, [
            'commande', 'commander', 'commandes',
            'acheter', 'achete', 'achetes', 'achat',
            'je veux', 'je voudrais', 'voudrais', 'voulez',
            'je prends', 'je prend', 'prendre', 'prend',
            'donner', 'donne', 'donnes', 'donnez',
            'je choisis', 'je choisi', 'choisir',
            'j\'aimerais', 'aimerais', 'aimer',
            'il me faut', 'faut', 'besoin',
            'offrir', 'offre', 'envoyer',
            'pour moi', 'pour nous',
        ]);
    }

    /**
     * @param  array<int, string>  $mots
     */
    private function contient(string $texte, array $mots): bool
    {
        foreach ($mots as $mot) {
            if (str_contains($texte, $mot)) {
                return true;
            }
        }

        return false;
    }

    private function formatProduit(Produit $produit): array
    {
        return [
            'id' => $produit->id,
            'nom' => $produit->nom,
            'prix' => (float) $produit->prix,
            'stock' => (int) $produit->stock,
        ];
    }

    private function traiterPaiement(Client $client, string $canal): array
    {
        $derniereCommande = $client->commandes()->latest()->first();

        if (! $derniereCommande) {
            return [
                'message' => "Pour payer, passez d'abord une commande. Vous pouvez choisir un produit et je vous guiderai.",
                'suggestions' => ['Voir les produits', 'Afficher les prix'],
            ];
        }

        $statut = $derniereCommande->statut_label;
        $reste = (float) $derniereCommande->reste_a_payer;

        if ($derniereCommande->statut === 'livree') {
            return [
                'message' => "Votre commande {$derniereCommande->numero} est déjà livrée. Merci pour votre confiance !",
                'suggestions' => ['Voir les produits'],
            ];
        }

        if ($derniereCommande->statut === 'annulee') {
            return [
                'message' => "Votre commande {$derniereCommande->numero} a été annulée. Vous pouvez en passer une nouvelle.",
                'suggestions' => ['Voir les produits', 'Afficher les prix'],
            ];
        }

        $montant = number_format($reste, 0, ',', ' ');
        $texte = "Commande {$derniereCommande->numero} — {$statut}\n"
            . "Reste à payer : {$montant} Ar\n\n"
            . "Modes de paiement acceptés :\n";

        $lienPaiement = $reste > 0 ? $this->lienPaiementStripe($derniereCommande, $reste) : null;
        $texte .= $this->ligneCarte($lienPaiement, $canal);

        $texte .= "• Mobile Money (MVola, Airtel Money, Orange Money)\n"
            . "• Virement bancaire\n"
            . "• Paiement à la livraison\n\n"
            . "Un commercial vous contactera pour finaliser.";

        return [
            'message' => $texte,
            'lien_paiement' => $lienPaiement,
            'suggestions' => ['Voir les produits', 'Suivre ma commande'],
        ];
    }

    /**
     * Génère le lien de paiement Stripe si le service est configuré.
     * Retourne null si Stripe n'est pas disponible.
     */
    private function lienPaiementStripe(Commande $commande, float $montant): ?string
    {
        try {
            return $this->stripe->creerSessionCheckout($commande, $montant)['url'];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Ajoute la ligne « carte bancaire » au texte d'un message.
     * Sur Telegram et WhatsApp l'URL brute est remplacée par un bouton (champ lien_paiement).
     */
    private function ligneCarte(?string $lien, string $canal): string
    {
        if (! $lien) {
            return '';
        }

        return in_array($canal, ['Telegram', 'WhatsApp'])
            ? "• 💳 Carte bancaire (en ligne) : touchez le bouton « 💳 Payer par carte » ci-dessous\n"
            : "• 💳 Carte bancaire (en ligne) : {$lien}\n";
    }

    private function traiterLivraison(Client $client): array
    {
        $derniereCommande = $client->commandes()->latest()->first();

        if (! $derniereCommande) {
            return [
                'message' => "Vous n'avez pas encore de commande. Passez une commande et nous l'organiserons la livraison pour vous.",
                'suggestions' => ['Voir les produits', 'Afficher les prix'],
            ];
        }

        $statut = $derniereCommande->statut_label;

        $suivi = match ($derniereCommande->statut) {
            'en_attente' => "Votre commande est en attente de confirmation. Elle sera préparée sous 24-48h.",
            'confirmee' => "Votre commande est confirmée et en cours de préparation. Expédition sous 24-48h.",
            'expediee' => "Votre commande est expédiée ! Livraison prévue sous 1-3 jours ouvrés.",
            'livree' => "Votre commande a été livrée. Nous espérons que vous êtes satisfait !",
            'annulee' => "Votre commande a été annulée.",
            default => "Statut inconnu.",
        };

        return [
            'message' => "Commande {$derniereCommande->numero} — {$statut}\n\n{$suivi}\n\nPour plus de détails, contactez directement le vendeur.",
            'suggestions' => ['Payer ma commande', 'Voir les produits'],
        ];
    }

    private function traiterAnnulation(Entreprise $entreprise, Client $client): array
    {
        $commande = $client->commandes()
            ->where('entreprise_id', $entreprise->id)
            ->whereIn('statut', ['en_attente', 'confirmee'])
            ->latest()
            ->first();

        if (! $commande) {
            return [
                'message' => "Vous n'avez aucune commande en cours à annuler.",
                'suggestions' => ['Voir les produits', 'Afficher les prix'],
            ];
        }

        $lignes = $commande->items->map(fn ($item) => $item->quantite . ' ' . $item->produit_nom)->implode(', ');

        app(CommandeService::class)->changerStatut($commande, 'annulee');

        return [
            'message' => "Votre commande de {$lignes} a bien été annulée.\n\nSi vous souhaitez passer une nouvelle commande, dites-moi ce que vous voulez.",
            'suggestions' => ['Voir les produits', 'Afficher les prix'],
        ];
    }

    private function traiterModification(Entreprise $entreprise, Client $client, string $message, string $canal): array
    {
        $commande = $client->commandes()
            ->where('entreprise_id', $entreprise->id)
            ->where('statut', 'en_attente')
            ->latest()
            ->first();

        if (! $commande) {
            $aCommande = $client->commandes()
                ->where('entreprise_id', $entreprise->id)
                ->latest()
                ->first();

            if ($aCommande && in_array($aCommande->statut, ['confirmee', 'expediee', 'livree'])) {
                return [
                    'message' => "Votre commande {$aCommande->numero} est déjà {$aCommande->statut_label}. Elle ne peut plus être modifiée.",
                    'suggestions' => ['Voir les produits'],
                ];
            }

            return [
                'message' => "Vous n'avez pas de commande en attente a modifier.\n\nPour changer une commande, dites-moi les nouveaux produits que vous souhaitez.",
                'suggestions' => ['Voir les produits', 'Afficher les prix'],
            ];
        }

        $ancienMontant = number_format((float) $commande->montant_total, 0, ',', ' ');

        $analyse = $this->parser->parser($message, $entreprise->produits()->get());

        if (! empty($analyse['reconnus'])) {
            $ruptures = [];
            foreach ($analyse['reconnus'] as $ligne) {
                $produit = $ligne['produit'];
                if ($produit->stock < $ligne['quantite']) {
                    $ruptures[] = "{$produit->nom} (stock : {$produit->stock})";
                }
            }

            if (! empty($ruptures)) {
                return [
                    'message' => "Certains produits ne sont pas disponibles en quantité suffisante : "
                        . implode(', ', $ruptures) . ".\n\nVotre commande actuelle est conservée.",
                    'suggestions' => ['Voir les produits'],
                    'ruptures' => $ruptures,
                ];
            }

            app(CommandeService::class)->changerStatut($commande, 'annulee');

            $nouvelleCommande = $this->commandes->creerDepuisMessage($entreprise, $client, $message, $canal)['commande'];

            $lignes = $nouvelleCommande->items->map(fn ($item) => $item->quantite . ' ' . $item->produit_nom);
            $count = $lignes->count();
            if ($count === 1) {
                $listeProduits = $lignes->first();
            } elseif ($count === 2) {
                $listeProduits = $lignes->first() . ' et ' . $lignes->last();
            } else {
                $dernier = $lignes->pop();
                $listeProduits = $lignes->implode(', ') . ' et ' . $dernier;
            }

            $nouveauMontant = number_format((float) $nouvelleCommande->montant_total, 0, ',', ' ');

            return [
                'message' => "Votre commande a bien été modifiée !\n"
                    . "Ancienne commande ({$ancienMontant} Ar) : annulée.\n"
                    . "Nouvelle commande ({$nouveauMontant} Ar) : {$listeProduits}.\n\n"
                    . "On va la préparer et vous recontacter pour la livraison. Merci !",
                'suggestions' => $this->suggestions($entreprise->produits()->get()),
                'commande' => [
                    'numero' => $nouvelleCommande->numero,
                    'montant_total' => (float) $nouvelleCommande->montant_total,
                    'statut' => $nouvelleCommande->statut,
                    'statut_label' => $nouvelleCommande->statut_label,
                    'facture_numero' => null,
                ],
                'ruptures' => [],
            ];
        }

        $ancienLignes = $commande->items->map(fn ($item) => $item->quantite . ' ' . $item->produit_nom)->implode(', ');

        return [
            'message' => "Votre commande actuelle ({$ancienMontant} Ar) : {$ancienLignes}\n\n"
                . "Dites-moi les nouveaux produits que vous souhaitez. Par exemple :\n"
                . "- \"modifier 3 cheese burger\"\n"
                . "- \"changer pour 2 pizza reine\"",
            'suggestions' => ['Voir les produits', 'Afficher les prix', 'Annuler ma commande'],
        ];
    }
}
