<?php

namespace App\Events;

use App\Http\Resources\CommandeResource;
use App\Models\Commande;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommandeCreee implements ShouldBroadcastNow
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
        return 'commande.creee';
    }

    public function broadcastWith(): array
    {
        return ['commande' => new CommandeResource($this->commande)];
    }
}
