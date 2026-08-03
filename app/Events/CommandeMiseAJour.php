<?php

namespace App\Events;

use App\Http\Resources\CommandeResource;
use App\Models\Commande;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommandeMiseAJour implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Commande $commande)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('entreprise.' . $this->commande->entreprise_id)];
    }

    public function broadcastAs(): string
    {
        return 'commande.mise_a_jour';
    }

    public function broadcastWith(): array
    {
        return ['commande' => new CommandeResource($this->commande)];
    }
}
