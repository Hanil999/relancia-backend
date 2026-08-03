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

        // Une salutation ou une question de prix est une question, pas une commande,
        // même si un produit y est mentionné ("Combien coûte le collier ?").
        if ($this->contient($texte, ['bonjour', 'salut', 'bonsoir', 'hello', 'coucou', 'slt', 'cc', 'prix', 'tarif', 'combien', 'coute', 'coût', 'cout'])) {
            return [...$base, ...$this->traiterQuestion($message, $produits)];
        }

        $analyse = $this->parser->parser($message, $produits);

        if (! empty($analyse['reconnus'])) {
            return [...$base, ...$this->traiterCommande($entreprise, $client, $message, $canal, $analyse['reconnus'])];
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

        // Confirmation automatique : prélève le stock et génère la facture.
        $this->commandes->changerStatut($commande, 'confirmee');

        // changerStatut a pré-chargé la relation facture (null) avant sa création :
        // on recharge pour récupérer la facture réellement générée.
        $commande = $commande->fresh(['items', 'client', 'facture']);
        $facture = $commande->facture;

        $texte = "Commande {$commande->numero} confirmée ✅\n"
            . 'Montant : ' . number_format((float) $commande->montant_total, 0, ',', ' ') . " Ar\n"
            . ($facture ? "Facture {$facture->numero} générée." : '')
            . "\nMerci, un commercial vous recontactera pour la livraison.";

        return [
            'message' => trim($texte),
            'suggestions' => $this->suggestions($entreprise->produits()->get()),
            'commande' => [
                'numero' => $commande->numero,
                'montant_total' => (float) $commande->montant_total,
                'statut' => $commande->statut,
                'statut_label' => $commande->statut_label,
                'facture_numero' => $facture?->numero,
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
}
