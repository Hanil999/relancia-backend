<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'telephone' => $this->telephone,
            'email' => $this->email,
            'canal_prefere' => $this->canal_prefere,
            'commandes_count' => $this->commandes_count ?? 0,
            'created_at' => $this->created_at,
        ];
    }
}
