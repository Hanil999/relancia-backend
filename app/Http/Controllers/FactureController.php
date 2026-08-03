<?php

namespace App\Http\Controllers;

use App\Http\Resources\FactureResource;
use App\Models\Commande;
use App\Models\Entreprise;
use App\Models\Facture;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\Storage;

class FactureController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices)
    {
    }

    /**
     * Liste les factures de l'entreprise (avec client, montant et statut de paiement).
     */
    public function index(Entreprise $entreprise)
    {
        $this->authorize('voirCommandes', $entreprise);

        $factures = Facture::where('entreprise_id', $entreprise->id)
            ->with(['commande.client', 'commande.paiements'])
            ->latest()
            ->paginate(20);

        return FactureResource::collection($factures);
    }

    /**
     * Génère manuellement la facture PDF d'une commande confirmée.
     */
    public function generer(Entreprise $entreprise, Commande $commande)
    {
        $this->authorize('gererCommandes', $entreprise);
        abort_if($commande->entreprise_id !== $entreprise->id, 404);
        abort_unless(
            in_array($commande->statut, ['confirmee', 'expediee', 'livree'], true),
            422,
            'La facture ne peut être générée que pour une commande confirmée.',
        );

        $facture = $this->invoices->genererPourCommande($commande);

        return new FactureResource($facture);
    }

    public function telecharger(Entreprise $entreprise, Facture $facture)
    {
        $this->authorize('voirCommandes', $entreprise);
        abort_if($facture->entreprise_id !== $entreprise->id, 404);

        return Storage::disk('public')->download($facture->chemin_pdf, "{$facture->numero}.pdf");
    }
}
