<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Moteur de compréhension "maison" pour transformer un message client en
 * commande structurée, sans appel à un LLM externe : découpage du message,
 * extraction de quantités (chiffres ou mots en français) puis rapprochement
 * flou (fuzzy matching) avec le catalogue produits de l'entreprise.
 */
class CommandeParserService
{
    private const MOTS_NOMBRES = [
        'un' => 1, 'une' => 1, 'deux' => 2, 'trois' => 3, 'quatre' => 4,
        'cinq' => 5, 'six' => 6, 'sept' => 7, 'huit' => 8, 'neuf' => 9,
        'dix' => 10, 'onze' => 11, 'douze' => 12,
    ];

    /** Score minimum (0-100) de similarité texte pour valider un rapprochement produit. */
    private const SEUIL_SIMILARITE = 55;

    /**
     * @param  Collection<int, \App\Models\Produit>  $produits  Catalogue de l'entreprise.
     * @return array{
     *     reconnus: array<int, array{produit: \App\Models\Produit, quantite: int, confiance: float}>,
     *     non_reconnus: array<int, string>,
     * }
     */
    public function parser(string $message, Collection $produits): array
    {
        $segments = $this->decouperEnSegments($message);

        $reconnus = [];
        $nonReconnus = [];

        foreach ($segments as $segment) {
            [$quantite, $texteProduit] = $this->extraireQuantite($segment);

            if (trim($texteProduit) === '') {
                continue;
            }

            $meilleur = $this->trouverMeilleurProduit($texteProduit, $produits);

            if ($meilleur === null) {
                $nonReconnus[] = trim($segment);
                continue;
            }

            $reconnus[] = [
                'produit' => $meilleur['produit'],
                'quantite' => $quantite,
                'confiance' => $meilleur['score'],
            ];
        }

        return ['reconnus' => $reconnus, 'non_reconnus' => $nonReconnus];
    }

    /**
     * Découpe "deux pizza royale et une coca" en segments indépendants.
     *
     * @return array<int, string>
     */
    private function decouperEnSegments(string $message): array
    {
        $normalise = str_replace([' & ', '+', "\n"], ',', $message);
        $normalise = preg_replace('/\s+et\s+/iu', ',', $normalise);

        return array_values(array_filter(array_map('trim', explode(',', $normalise))));
    }

    /**
     * Extrait une quantité en tête de segment ("2 pizzas" / "deux pizzas" / "pizza").
     * Par défaut, quantité = 1 si aucun nombre n'est détecté.
     *
     * @return array{0: int, 1: string}
     */
    private function extraireQuantite(string $segment): array
    {
        $mots = implode('|', array_keys(self::MOTS_NOMBRES));

        if (preg_match('/^\s*(\d+)\s+(.+)$/u', $segment, $m)) {
            return [(int) $m[1], $m[2]];
        }

        if (preg_match('/^\s*(' . $mots . ')\s+(.+)$/iu', $segment, $m)) {
            return [self::MOTS_NOMBRES[mb_strtolower($m[1])], $m[2]];
        }

        // Quantité placée en milieu de phrase : "je veux 2 colliers", "je voudrais deux bracelets".
        if (preg_match('/\b(\d+)\b/u', $segment, $m)) {
            $texte = preg_replace('/\b\d+\b/', ' ', $segment, 1);

            return [(int) $m[1], $this->reduireEspaces($texte)];
        }

        foreach (self::MOTS_NOMBRES as $mot => $valeur) {
            if (preg_match('/\b' . $mot . '\b/iu', $segment)) {
                $texte = preg_replace('/\b' . $mot . '\b/iu', ' ', $segment, 1);

                return [$valeur, $this->reduireEspaces($texte)];
            }
        }

        return [1, $segment];
    }

    private function reduireEspaces(string $texte): string
    {
        return trim(preg_replace('/\s+/', ' ', $texte));
    }

    /**
     * Recherche le produit le plus proche du texte donné par similarité textuelle.
     *
     * @param  Collection<int, \App\Models\Produit>  $produits
     * @return array{produit: \App\Models\Produit, score: float}|null
     */
    private function trouverMeilleurProduit(string $texte, Collection $produits): ?array
    {
        $texteNormalise = $this->normaliser($texte);

        $meilleurScore = 0.0;
        $meilleurProduit = null;

        foreach ($produits as $produit) {
            $nomNormalise = $this->normaliser($produit->nom);

            similar_text($texteNormalise, $nomNormalise, $pourcentage);

            // Bonus si le nom du produit est entièrement contenu dans le message
            // (ex: message "je veux royale" et produit "pizza royale").
            if ($nomNormalise !== '' && str_contains($texteNormalise, $nomNormalise)) {
                $pourcentage = max($pourcentage, 90.0);
            }

            if ($pourcentage > $meilleurScore) {
                $meilleurScore = $pourcentage;
                $meilleurProduit = $produit;
            }
        }

        if ($meilleurProduit === null || $meilleurScore < self::SEUIL_SIMILARITE) {
            return null;
        }

        return ['produit' => $meilleurProduit, 'score' => $meilleurScore];
    }

    /**
     * Minuscules, sans accents, sans ponctuation superflue — pour un matching robuste.
     */
    private function normaliser(string $texte): string
    {
        $texte = mb_strtolower(trim($texte));
        $transliterations = [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u',
            'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ];
        $texte = strtr($texte, $transliterations);
        $texte = preg_replace('/[^a-z0-9\s]/', ' ', $texte);

        return trim(preg_replace('/\s+/', ' ', $texte));
    }
}
