<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OcrService
{
    private const API_URL = 'https://api.ocr.space/parse/image';

    /**
     * Extrait le texte d'une image via ocr.space.
     * Utilise uniquement base64Image (pas de multipart attach).
     */
    public function extraireTexte(UploadedFile $fichier): string
    {
        $contenu = file_get_contents($fichier->getRealPath());
        $mimeType = $fichier->getMimeType() ?: 'image/jpeg';
        $nomFichier = $fichier->getClientOriginalName() ?: 'preuve.jpg';

        $response = Http::timeout(30)->attach(
            'file', $contenu, $nomFichier, ['Content-Type' => $mimeType]
        )->post(self::API_URL, [
            'apikey' => 'K85345927388957',
            'language' => 'fre',
            'isOverlayRequired' => 'false',
            'scale' => 'true',
            'OCREngine' => '2',
        ]);

        if (! $response->successful()) {
            Log::warning('OCR API echec HTTP', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);
            return '';
        }

        $json = $response->json();

        if (isset($json['IsErroredOnProcessing']) && $json['IsErroredOnProcessing'] === true) {
            Log::warning('OCR API erreur traitement', [
                'message' => $json['ErrorMessage'] ?? [],
                'error_details' => $json['ErrorDetails'] ?? null,
            ]);
            return '';
        }

        $parsed = $json['ParsedResults'] ?? [];
        $texte = collect($parsed)
            ->pluck('ParsedText')
            ->implode(' ');

        Log::info('OCR reussi', [
            'texte' => substr($texte, 0, 300),
            'nb_results' => count($parsed),
        ]);

        return $texte;
    }

    /**
     * Extrait tous les montants (nombres) du texte OCR.
     * Gère les formats malgaches : "24 000", "24000", "24.000", "24 000 Ar", "Ar 24 000".
     *
     * @return array<int, float>
     */
    public function extraireMontants(string $texte): array
    {
        $texte = str_replace(["\xC2\xA0", "\u00A0"], ' ', $texte);

        $montants = [];

        // Nombres à 4+ chiffres, avec ou sans séparateurs
        $pattern = '/(?<!\d)([\d]{1,3}(?:[\s.][\d]{3})+|\d{4,})(?!\d)/u';

        if (preg_match_all($pattern, $texte, $matches)) {
            foreach ($matches[1] as $match) {
                $clean = preg_replace('/[\s.]/', '', $match);
                if (is_numeric($clean) && $clean > 0) {
                    $montants[] = (float) $clean;
                }
            }
        }

        return array_values(array_unique($montants));
    }

    /**
     * Compare le montant d'une commande avec les montants extraits de la photo.
     */
    public function verifierMontant(UploadedFile $fichier, float $montantAttendu, float $tolerance = 0.01): ?array
    {
        $texte = $this->extraireTexte($fichier);

        if (empty(trim($texte))) {
            return [
                'texte' => '',
                'montants_trouves' => [],
                'match' => false,
                'montant_detecte' => null,
                'message' => 'Aucun texte detecte sur l\'image.',
            ];
        }

        $montants = $this->extraireMontants($texte);

        $montantDetecte = null;
        foreach ($montants as $m) {
            if (abs($m - $montantAttendu) <= max($tolerance, 1)) {
                $montantDetecte = $m;
                break;
            }
        }

        if ($montantDetecte === null && count($montants) > 0) {
            usort($montants, fn ($a, $b) => abs($a - $montantAttendu) <=> abs($b - $montantAttendu));
            $montantDetecte = $montants[0];
        }

        $match = $montantDetecte !== null && abs($montantDetecte - $montantAttendu) <= max($tolerance, 1);
        $attenduFmt = number_format($montantAttendu, 0, ',', ' ');
        $detecteFmt = $montantDetecte !== null ? number_format($montantDetecte, 0, ',', ' ') : 'N/A';

        $message = $match
            ? "Montant verify : {$detecteFmt} Ar correspond a {$attenduFmt} Ar."
            : "Montant detecte : {$detecteFmt} Ar ne correspond pas a {$attenduFmt} Ar.";

        return [
            'texte' => $texte,
            'montants_trouves' => $montants,
            'match' => $match,
            'montant_detecte' => $montantDetecte,
            'message' => $message,
        ];
    }
}
