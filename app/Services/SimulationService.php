<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Entreprise;
use App\Models\Produit;
use Illuminate\Support\Collection;

/**
 * Moteur de simulation "Messenger" (sans IA ni Facebook réel) : reçoit le texte
 * d'un message client et produit la réponse que l'entreprise enverrait :
 *   - questions : présentation du catalogue, affichage des prix ;
 *   - commande   : vérification du stock, création + confirmation automatique
 *                  (stock décrémenté, facture générée), notification interne.
 */
class SimulationService
{
    public function __construct(
        private readonly CommandeParserService $parser,
        private readonly CommandeService $commandes,
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
        $texte = mb_strtolower($message);

        $base = [
            'client' => ['id' => $client->id, 'nom' => $client->nom],
            'produits' => null,
            'commande' => null,
            'ruptures' => [],
        ];

        // Salutations, prix, paiement, livraison → questions
        if ($this->contient($texte, [
            'bonjour', 'salut', 'bonsoir', 'hello', 'coucou', 'slt', 'cc',
            'prix', 'tarif', 'combien', 'coute', 'coût', 'cout', 'cher', 'moins cher',
            'catalogue', 'menu', 'carte', 'la liste',
        ])) {
            return [...$base, ...$this->traiterQuestion($message, $produits)];
        }

        if ($this->contient($texte, ['paiement', 'payer', 'comment payer', 'virement', 'mobile money', 'mvola', 'airtel money', 'orange money'])) {
            return [...$base, ...$this->traiterPaiement($client)];
        }

        if ($this->contient($texte, ['livraison', 'livrer', 'livre', 'délai', 'delai', 'recevoir', 'expedition', 'expédition'])) {
            return [...$base, ...$this->traiterLivraison($client)];
        }

        if ($this->contient($texte, ['annuler', 'cancel', 'supprimer', 'effacer'])) {
            return [...$base, ...$this->traiterAnnulation($entreprise, $client)];
        }

        if ($this->contient($texte, ['modifier', 'changer', 'remplacer'])) {
            return [...$base, ...$this->traiterModification($entreprise, $client, $message, $canal)];
        }

        $analyse = $this->parser->parser($message, $produits);

        if (! empty($analyse['reconnus'])) {
            return [...$base, ...$this->traiterCommande($entreprise, $client, $message, $canal, $analyse['reconnus'])];
        }

        if ($this->contient($texte, [
            'commande', 'commander', 'commandes',
            'acheter', 'achete', 'achat',
            'je veux', 'je voudrais', 'je veux', 'voudrais',
            'je prends', 'je prend', 'prendre', 'prend',
            'donner', 'donne', 'donnes', 'donnez',
            'je choisis', 'je choisi', 'choisir',
            'j\'aimerais', 'aimerais', 'aimer',
            'il me faut', 'faut', 'besoin',
            'offrir', 'offre', 'envoyer',
        ])) {
            return [...$base, 'message' => "Je n'ai pas reconnu le produit demandé. Voici notre catalogue :", ...$this->repondreCatalogue($produits)];
        }

        return [...$base, ...$this->traiterQuestion($message, $produits)];
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

        $texte = "Votre commande de {$listeProduits} est bien enregistree !\n"
            . 'Montant : ' . number_format((float) $commande->montant_total, 0, ',', ' ') . " Ar\n"
            . "\nOn va la preparer et vous recontacter pour la livraison. Merci !";

        return [
            'message' => trim($texte),
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
        $texte = mb_strtolower($message);

        if ($this->contient($texte, ['bonjour', 'salut', 'bonsoir', 'hello', 'coucou', 'slt', 'cc'])) {
            return [
                'message' => "Bonjour ! 👋 Bienvenue chez nous. Je peux vous montrer nos produits, vous donner les prix ou enregistrer votre commande. Que souhaitez-vous ?",
                'suggestions' => $this->suggestions($produits),
            ];
        }

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

        if ($this->contient($texte, ['produit', 'catalogue', 'liste', 'disponible', 'quoi', 'offre', 'stock', 'avoir'])) {
            return $this->repondreCatalogue($produits);
        }

        return [
            'message' => "Je n'ai pas bien compris votre demande. Voici ce que je peux faire pour vous :",
            'suggestions' => $this->suggestions($produits),
        ];
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

    private function traiterPaiement(Client $client): array
    {
        $derniereCommande = $client->commandes()->latest()->first();

        if (! $derniereCommande) {
            return [
                'message' => "Pour payer, passez d'abord une commande. Vous pouvez choisir un produit et je vous guiderai.",
                'suggestions' => ['Voir les produits', 'Afficher les prix'],
            ];
        }

        $montant = number_format((float) $derniereCommande->montant_total, 0, ',', ' ');
        $statut = $derniereCommande->statut_label;

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

        return [
            'message' => "Commande {$derniereCommande->numero} — {$statut}\nMontant à payer : {$montant} Ar\n\nModes de paiement acceptés :\n• Mobile Money (MVola, Airtel Money, Orange Money)\n• Virement bancaire\n• Paiement à la livraison\n\nUn commercial vous contactera pour finaliser.",
            'suggestions' => ['Voir les produits', 'Suivre ma commande'],
        ];
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
                'message' => "Vous n'avez aucune commande en cours a annuler.",
                'suggestions' => ['Voir les produits', 'Afficher les prix'],
            ];
        }

        $lignes = $commande->items->map(fn ($item) => $item->quantite . ' ' . $item->produit_nom)->implode(', ');

        app(CommandeService::class)->changerStatut($commande, 'annulee');

        return [
            'message' => "Votre commande de {$lignes} a bien ete annulee.\n\nSi vous souhaitez passer une nouvelle commande, dites-moi ce que vous voulez.",
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
                    'message' => "Votre commande {$aCommande->numero} est deja {$aCommande->statut_label}. Elle ne peut plus etre modifiee.",
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
                    'message' => "Certains produits ne sont pas disponibles en quantite suffisante : "
                        . implode(', ', $ruptures) . ".\n\nVotre commande actuelle est conservee.",
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
                'message' => "Votre commande a bien ete modifiee !\n"
                    . "Ancienne commande ({$ancienMontant} Ar) : annulee.\n"
                    . "Nouvelle commande ({$nouveauMontant} Ar) : {$listeProduits}.\n\n"
                    . "On va la preparer et vous recontacter pour la livraison. Merci !",
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
