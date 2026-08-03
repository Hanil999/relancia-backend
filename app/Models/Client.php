<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'nom',
        'telephone',
        'email',
        'canal_prefere',
        'identifiant_externe',
        'notes',
    ];

    public function entreprises(): BelongsToMany
{
    return $this->belongsToMany(
        Entreprise::class,
        'client_entreprise',
        'client_id',
        'entreprise_id'
    )
    ->withPivot([
        'plateforme_sociale',
        'identifiant_social',
        'premier_contact_le',
    ])
    ->withTimestamps();
}

    public function commandes(): HasMany
    {
        return $this->hasMany(Commande::class);
    }
}
