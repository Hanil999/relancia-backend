<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CanalEntreprise extends Model
{
    protected $table = 'canaux_entreprise';

    protected $fillable = [
        'entreprise_id', 'type', 'token', 'bot_username',
        'bot_id', 'webhook_secret', 'actif', 'connecte_le',
    ];

    protected $casts = [
        'token' => 'encrypted', // jamais stocké en clair en base
        'actif' => 'boolean',
        'connecte_le' => 'datetime',
    ];

    // Ne jamais renvoyer le token brut dans les réponses JSON
    protected $hidden = ['token', 'webhook_secret'];

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class);
    }
}
