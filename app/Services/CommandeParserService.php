<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Moteur de compréhension "maison" pour transformer un message client en
 * commande structurée, sans appel à un LLM externe : découpage du message,
 * extraction de quantités (chiffres ou mots en français) puis rapprochement
 * flou (fuzzy matching) avec le catalogue produits de l'entreprise.
 *
 * Améliorations :
 * - Synonymes / alias de produits (ex: "royale" → "Pizza reine")
 * - Levenshtein pour tolérer les fautes de frappe (ex: "piiza" → "Pizza")
 * - Matching par mots isolés (ex: "cheezburger" → "Cheese burger")
 * - Seuil abaissé pour capturer plus de rapprochements
 */
class CommandeParserService
{
    private const MOTS_NOMBRES = [
        'un' => 1, 'une' => 1, 'deux' => 2, 'trois' => 3, 'quatre' => 4,
        'cinq' => 5, 'six' => 6, 'sept' => 7, 'huit' => 8, 'neuf' => 9,
        'dix' => 10, 'onze' => 11, 'douze' => 12,
    ];

    /** Mots que l'on ignore dans le texte avant le matching produit. */
    private const MOTS_ARRETS = [
        'je', 'tu', 'il', 'elle', 'on', 'nous', 'vous', 'ils', 'elles',
        'veux', 'voudrais', 'veut', 'voulons', 'voulez',
        'prends', 'prend', 'prenons', 'prenez',
        'donne', 'donner', 'donnes', 'donnez',
        'commande', 'commander', 'commandes',
        'acheter', 'achete', 'achetes',
        'pour', 'pourtant', 'peux', 'pouvoir',
        'avec', 'sans', 'mais', 'ou', 'et',
        's\'il te plait', 'sil vous plait', 'svp', 'pls',
        'un', 'une', 'des', 'le', 'la', 'les', 'du', 'de', 'au',
        'ce', 'cette', 'ces', 'mon', 'ma', 'mes', 'ton', 'ta', 'tes',
        'la', 'lui', 'leur', 'leurs',
    ];

    /**
     * Alias / synonymes de produits : clé = forme normalisée, valeur = nom exact du produit.
     * Permet de matcher "royale" → "Pizza reine", "4 fromages" → "Pizza 4 fromages", etc.
     *
     * @var array<string, string>
     */
    private const ALIAS_PRODUITS = [
        // Pizzas
        'royale'            => 'Pizza reine',
        'reine'             => 'Pizza reine',
        'pizza reine'       => 'Pizza reine',
        'margherita'        => 'Pizza margherita',
        'marguerite'        => 'Pizza margherita',
        '4 fromages'        => 'Pizza 4 fromages',
        'quatre fromages'   => 'Pizza 4 fromages',
        '4 fromage'         => 'Pizza 4 fromages',
        'fromagee'          => 'Pizza 4 fromages',
        '4 saisons'         => 'Pizza 4 saisons',
        'quatre saisons'    => 'Pizza 4 saisons',
        '4 saison'          => 'Pizza 4 saisons',
        // Burgers
        'cheeseburger'      => 'Cheese burger',
        'cheezburger'       => 'Cheese burger',
        'cheesburger'       => 'Cheese burger',
        'cheese burger'     => 'Cheese burger',
        'chickenburger'     => 'Chicken burger',
        'chicken burger'    => 'Chicken burger',
        'double cheese'     => 'Double cheese burger',
        'double cheeseburger' => 'Double cheese burger',
        'doubleburger'      => 'Double cheese burger',
        // Poulet
        'poulet pane'       => 'Poulet pané',
        'poulet pané'       => 'Poulet pané',
        'poulet grille'     => 'Poulet grillé',
        'poulet grilee'     => 'Poulet grillé',
        'poulet grillee'    => 'Poulet grillé',
        'poulet grill'      => 'Poulet grillé',
        // Croquette
        'croquette'         => 'croquette de poulet',
        'croquettes'        => 'croquette de poulet',
        'croquette poulet'  => 'croquette de poulet',
    ];

    /** Score minimum (0-100) de similarité texte pour valider un rapprochement produit. */
    private const SEUIL_SIMILARITE = 45;

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
     * Recherche le produit le plus proche du texte donné.
     *
     * @param  Collection<int, \App\Models\Produit>  $produits
     * @return array{produit: \App\Models\Produit, score: float}|null
     */
    private function trouverMeilleurProduit(string $texte, Collection $produits): ?array
    {
        $texteNormalise = $this->normaliser($texte);
        $texteSansArrets = $this->supprimerMotsArrets($texteNormalise);
        $texteMots = array_filter(explode(' ', $texteSansArrets));

        // 1. Alias / synonymes directs (correspondance exacte ou partielle)
        $aliasResult = $this->chercherParAlias($texteSansArrets, $produits);
        if ($aliasResult !== null) {
            return $aliasResult;
        }

        $meilleurScore = 0.0;
        $meilleurProduit = null;

        foreach ($produits as $produit) {
            $nomNormalise = $this->normaliser($produit->nom);
            $score = 0.0;

            // 2. Conteneur : le nom du produit est entièrement contenu dans le texte
            if ($nomNormalise !== '' && str_contains($texteNormalise, $nomNormalise)) {
                $score = max($score, 95.0);
            }

            // 3. similar_text classique
            similar_text($texteNormalise, $nomNormalise, $pourcentage);
            $score = max($score, $pourcentage);

            // 4. Levenshtein (fautes de frappe)
            $levScore = $this->scoreLevenshtein($texteNormalise, $nomNormalise);
            $score = max($score, $levScore);

            // 5. Matching par mots isolés : chaque mot du produit est trouvé dans le texte
            $motScore = $this->scoreParMots($texteMots, $nomNormalise);
            $score = max($score, $motScore);

            // 6. Levenshtein sur chaque mot isolé
            $nomMots = array_filter(explode(' ', $nomNormalise));
            foreach ($nomMots as $nomMot) {
                foreach ($texteMots as $texteMot) {
                    if (mb_strlen($texteMot) < 3 || mb_strlen($nomMot) < 3) {
                        continue;
                    }
                    similar_text($texteMot, $nomMot, $ms);
                    $lev = levenshtein($texteMot, $nomMot);
                    $maxLen = max(mb_strlen($texteMot), mb_strlen($nomMot));
                    $levFromDist = $maxLen > 0 ? (1 - $lev / $maxLen) * 100 : 0;
                    $score = max($score, max($ms, $levFromDist));
                }
            }

            if ($score > $meilleurScore) {
                $meilleurScore = $score;
                $meilleurProduit = $produit;
            }
        }

        if ($meilleurProduit === null || $meilleurScore < self::SEUIL_SIMILARITE) {
            return null;
        }

        return ['produit' => $meilleurProduit, 'score' => $meilleurScore];
    }

    /**
     * Cherche une correspondance via les alias / synonymes de produits.
     */
    private function chercherParAlias(string $texteSansArrets, Collection $produits): ?array
    {
        foreach (self::ALIAS_PRODUITS as $alias => $nomExact) {
            $aliasNorm = $this->normaliser($alias);

            if (! str_contains($texteSansArrets, $aliasNorm)) {
                continue;
            }

            $produit = $produits->first(fn ($p) => mb_strtolower($p->nom) === mb_strtolower($nomExact));

            if ($produit !== null) {
                return ['produit' => $produit, 'score' => 98.0];
            }
        }

        return null;
    }

    /**
     * Score Levenshtein normalisé (0-100) entre deux chaînes.
     */
    private function scoreLevenshtein(string $a, string $b): float
    {
        $maxLen = max(mb_strlen($a), mb_strlen($b));

        if ($maxLen === 0) {
            return 0.0;
        }

        $dist = levenshtein($a, $b);

        return (1 - $dist / $maxLen) * 100;
    }

    /**
     * Score basé sur la proportion de mots du produit trouvés dans le texte.
     * "cheezburger" vs "cheese burger" → les deux mots sont trouvés → score élevé.
     */
    private function scoreParMots(array $texteMots, string $nomNormalise): float
    {
        $nomMots = array_filter(explode(' ', $nomNormalise));

        if (empty($nomMots)) {
            return 0.0;
        }

        $trouves = 0;

        foreach ($nomMots as $nomMot) {
            foreach ($texteMots as $texteMot) {
                if ($texteMot === $nomMot) {
                    $trouves++;
                    break;
                }

                if (mb_strlen($nomMot) >= 3 && mb_strlen($texteMot) >= 3) {
                    similar_text($texteMot, $nomMot, $pct);
                    if ($pct >= 75) {
                        $trouves++;
                        break;
                    }

                    $lev = levenshtein($texteMot, $nomMot);
                    $maxLen = max(mb_strlen($texteMot), mb_strlen($nomMot));
                    if ($maxLen > 0 && ($maxLen - $lev) / $maxLen >= 0.6) {
                        $trouves++;
                        break;
                    }
                }
            }
        }

        return ($trouves / count($nomMots)) * 100;
    }

    /**
     * Supprime les mots courants (verbes, déterminants…) pour ne garder que
     * les mots susceptibles de porter le nom du produit.
     */
    private function supprimerMotsArrets(string $texte): string
    {
        $mots = explode(' ', $texte);
        $filtrés = array_diff($mots, self::MOTS_ARRETS);

        return trim(implode(' ', $filtrés));
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
