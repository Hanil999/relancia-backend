<?php

namespace App\Events;

use App\Models\NotificationInterne;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationCreee implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public NotificationInterne $notification)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('entreprise.' . $this->notification->entreprise_id)];
    }

    public function broadcastAs(): string
    {
        return 'notification.creee';
    }

    public function broadcastWith(): array
    {
        return [
            'notification' => [
                'id' => $this->notification->id,
                'type' => $this->notification->type,
                'titre' => $this->notification->titre,
                'message' => $this->notification->message,
                'data' => $this->notification->data,
                'cree_le' => $this->notification->created_at,
            ],
        ];
    }
}
