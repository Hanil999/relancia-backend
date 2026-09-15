<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCommandeRequest;
use App\Http\Requests\UpdateStatutCommandeRequest;
use App\Http\Resources\CommandeResource;
use App\Models\Client;
use App\Models\Commande;
use App\Models\Entreprise;
use App\Services\CommandeService;
use Illuminate\Http\Request;

class CommandeController extends Controller
{
    public function __construct(private readonly CommandeService $commandes)
    {
    }

    public function index(Request $request, Entreprise $entreprise)
    {
        $this->authorize('voirCommandes', $entreprise);

        $commandes = $entreprise->commandes()
            ->when($request->boolean('archives'), fn ($q) => $q->onlyTrashed())
            ->when($request->statut, fn ($q, $s) => $q->where('statut', $s))
            ->when($request->canal, fn ($q, $c) => $q->where('canal', $c))
            ->when($request->recherche, fn ($q, $r) => $q->whereHas(
                'client',
                fn ($cq) => $cq->where('nom', 'like', "%{$r}%")
            ))
            ->with(['client', 'items', 'facture', 'paiements'])
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return CommandeResource::collection($commandes);
    }

    /**
     * Création manuelle depuis le formulaire "Nouvelle commande" du frontend.
     */
    public function store(StoreCommandeRequest $request, Entreprise $entreprise)
    {
        $this->authorize('gererCommandes', $entreprise);

        $data = $request->validated();

       $plateforme = match ($data['canal'] ?? 'Manuel') {
    'Messenger' => 'facebook',
    'Instagram' => 'instagram',
    'WhatsApp' => 'whatsapp',
    default => 'autre',
};

if (isset($data['client_id'])) {

    // Client existant
    $client = $entreprise->clients()->findOrFail($data['client_id']);

} else {

    // Nouveau client
    $client = Client::create([
        'nom' => $data['client_nom'],
        'telephone' => $data['client_telephone'] ?? null,
    ]);

    $entreprise->clients()->attach($client->id, [
        'plateforme_sociale' => $plateforme,
        'premier_contact_le' => now(),
    ]);
}

        $commande = $this->commandes->creerManuellement(
            $entreprise,
            $client,
            $data['items'],
            $data['canal'] ?? 'Manuel',
        );

        return new CommandeResource($commande);
    }

    public function show(Entreprise $entreprise, Commande $commande)
    {
        $this->authorize('voirCommandes', $entreprise);
        abort_if($commande->entreprise_id !== $entreprise->id, 404);

        return new CommandeResource($commande->load('client', 'items.produit', 'facture', 'paiements'));
    }

    public function update(StoreCommandeRequest $request, Entreprise $entreprise, Commande $commande)
    {
        $this->authorize('gererCommandes', $entreprise);
        abort_if($commande->entreprise_id !== $entreprise->id, 404);

        $data = $request->validated();

        $client = isset($data['client_id'])
            ? $entreprise->clients()->findOrFail($data['client_id'])
            : $entreprise->clients()->create([
                'nom' => $data['client_nom'],
                'telephone' => $data['client_telephone'] ?? null,
            ]);

        $commande = $this->commandes->modifier(
            $commande,
            $client,
            $data['items'],
            $data['canal'] ?? $commande->canal,
        );

        return new CommandeResource($commande);
    }

    /**
     * Archive (suppression logique) : la commande disparaît de la liste active
     * mais reste restaurable. Le stock n'est pas modifié ici.
     */
    public function destroy(Entreprise $entreprise, Commande $commande)
    {
        $this->authorize('gererCommandes', $entreprise);
        abort_if($commande->entreprise_id !== $entreprise->id, 404);

        $commande->delete();

        return response()->noContent();
    }

    /**
     * Restaure une commande archivée.
     */
    public function restore(Entreprise $entreprise, int $commandeId)
    {
        $this->authorize('gererCommandes', $entreprise);

        $commande = Commande::withTrashed()->findOrFail($commandeId);
        abort_if($commande->entreprise_id !== $entreprise->id, 404);

        $commande->restore();

        return new CommandeResource($commande->load('client', 'items', 'facture'));
    }

    public function updateStatut(UpdateStatutCommandeRequest $request, Entreprise $entreprise, Commande $commande)
    {
        $this->authorize('gererCommandes', $entreprise);
        abort_if($commande->entreprise_id !== $entreprise->id, 404);

        $commande = $this->commandes->changerStatut($commande, $request->validated()['statut']);

        return new CommandeResource($commande->load('client', 'items', 'facture'));
    }
}
