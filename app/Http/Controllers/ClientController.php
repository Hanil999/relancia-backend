<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\Entreprise;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function index(Request $request, Entreprise $entreprise)
    {
        $this->authorize('voirCommandes', $entreprise);

        $clients = $entreprise->clients()
            ->when($request->search, fn ($q, $s) => $q->where('nom', 'like', "%{$s}%"))
            ->withCount('commandes')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ClientResource::collection($clients);
    }

    public function store(Request $request, Entreprise $entreprise)
    {
        $this->authorize('gererCommandes', $entreprise);

        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
            'canal_prefere' => ['nullable', 'string', 'max:50'],
        ]);

        $client = $entreprise->clients()->create($data);
        $this->notifications->nouveauClient($entreprise, $client);

        return new ClientResource($client);
    }

    public function show(Entreprise $entreprise, Client $client)
    {
        $this->authorize('voirCommandes', $entreprise);
        abort_unless($entreprise->clients()->whereKey($client->id)->exists(), 404);

        return new ClientResource($client->loadCount('commandes'));
    }
}
