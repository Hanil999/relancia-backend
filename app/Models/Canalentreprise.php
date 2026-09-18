<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CanalEntreprise extends Model
{
    protected $table = 'canaux_entreprise';

    protected $fillable = [
        'entreprise_id', 'type', 'token', 'bot_username',
        'bot_id', 'waba_id', 'webhook_secret', 'app_secret', 'actif', 'connecte_le',
    ];

    protected $casts = [
        'token' => 'encrypted', // jamais stocké en clair en base
        'app_secret' => 'encrypted',
        'actif' => 'boolean',
        'connecte_le' => 'datetime',
    ];

    // Ne jamais renvoyer le token brut dans les réponses JSON
    protected $hidden = ['token', 'app_secret', 'webhook_secret'];

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class);
    }
}
