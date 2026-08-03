<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'titre' => $this->titre,
            'message' => $this->message,
            'data' => $this->data,
            'lu_le' => $this->lu_le?->toDateTimeString(),
            'creee_le' => $this->created_at?->toDateTimeString(),
        ];
    }
}
